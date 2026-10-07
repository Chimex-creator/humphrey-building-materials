<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use App\Notifications\DeliveryUpdated;
use App\Notifications\OrderStatusChanged;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PHASE 7 (completion) - delivery management (Final Spec §22/§45).
 *
 * The delivery board is the operational view: which order, to whom, on what
 * date, and what went wrong if it failed. There are NO delivery trips —
 * delivery records stand alone.
 *
 * Money and the customer-facing lifecycle stay on the Order. When a delivery
 * status is also a valid order status (out_for_delivery, delivered) we move
 * the order too, but only through the normal state machine - if the move is
 * not allowed the delivery still saves and the staff member is told plainly
 * to finish it from the order page.
 */
class DeliveryController extends Controller
{
    /** Delivery board: search + status filters. */
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(array_keys(Delivery::STATUSES))],
        ]);

        $search = trim($data['search'] ?? '');
        $status = $data['status'] ?? '';

        $this->reconcile();

        $query = Delivery::query()
            ->with([
                'order:id,order_number,customer_name,customer_phone,delivery_address,preferred_delivery_date,status,total,delivery_option',
                'assignee',
            ])
            ->whereHas('order', fn ($q) => $q->where('delivery_option', 'delivery'))
            ->whereDoesntHave('order', fn ($q) => $q->where('status', 'cancelled'))
            ->latest('deliveries.id');

        if ($search !== '') {
            $query->whereHas('order', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('order_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%");
                });
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        $deliveries = $query->paginate(12)->withQueryString();

        $statusCounts = Delivery::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.deliveries.index', [
            'deliveries' => $deliveries,
            'staff' => User::where('is_active', true)
                ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_INVENTORY, User::ROLE_SALES])
                ->orderBy('name')
                ->get(),
            'statusCounts' => $statusCounts,
            'search' => $search,
            'status' => $status,
        ]);
    }

    /** One delivery: the details, the assignment form and its order. */
    public function show(Delivery $delivery)
    {
        $delivery->load(['order.user', 'order.items', 'assignee']);

        return view('admin.deliveries.show', [
            'delivery' => $delivery,
            'staff' => User::where('is_active', true)
                ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_INVENTORY, User::ROLE_SALES])
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Save the delivery details (status, promised date, assignee, notes).
     *
     * PHASES 21–24 — a transition must explain itself:
     *  - "failed" needs a reason (+ optional note and a rescheduled date)
     *  - "delivered" needs a confirmation record (who received it)
     *  - every status change is written to the audit trail
     */
    public function update(Request $request, Delivery $delivery, ActivityLogger $logger)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Delivery::STATUSES))],
            'confirmed_date' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'failure_reason' => ['nullable', Rule::in(array_keys(Delivery::FAILURE_REASONS))],
            'failure_note' => ['nullable', 'string', 'max:500'],
            'rescheduled_date' => ['nullable', 'date', 'after_or_equal:today'],
            'received_by' => ['nullable', 'string', 'max:100'],
            'confirmation_note' => ['nullable', 'string', 'max:500'],
        ]);

        // A failed attempt must say WHY — structured reason, note if "other".
        if ($data['status'] === 'failed') {
            if (empty($data['failure_reason'])) {
                return back()->withInput()->withErrors([
                    'failure_reason' => 'A failed delivery needs a reason — pick what happened.',
                ]);
            }

            if ($data['failure_reason'] === 'other' && trim((string) ($data['failure_note'] ?? '')) === '') {
                return back()->withInput()->withErrors([
                    'failure_note' => 'Write what happened — "Other" needs a note.',
                ]);
            }
        }

        // Delivery is a record of who received the goods, not just a status.
        if ($data['status'] === 'delivered' && trim((string) ($data['received_by'] ?? '')) === '') {
            return back()->withInput()->withErrors([
                'received_by' => 'Who received the delivery? Enter their name for the confirmation record.',
            ]);
        }

        $previousStatus = $delivery->status;
        $previousDate = $delivery->confirmed_date?->toDateString();
        $dateChanged = ($data['confirmed_date'] ?? null) !== $previousDate;
        $becomingDelivered = $data['status'] === 'delivered' && $delivery->delivered_at === null;

        $delivery->fill([
            'status' => $data['status'],
            'confirmed_date' => ($data['confirmed_date'] ?? null) ?: null,
            'assigned_to' => ($data['assigned_to'] ?? null) ?: null,
            'notes' => $data['notes'] ?? null,
            'failure_reason' => $data['status'] === 'failed'
                ? ($data['failure_reason'] ?? $delivery->failure_reason)
                : $delivery->failure_reason,
            'failure_note' => $data['status'] === 'failed'
                ? trim((string) ($data['failure_note'] ?? '')) ?: $delivery->failure_note
                : $delivery->failure_note,
            'rescheduled_date' => $data['status'] === 'failed'
                ? (($data['rescheduled_date'] ?? null) ?: $delivery->rescheduled_date)
                : $delivery->rescheduled_date,
            // Proof of delivery: stamped once, never overwritten by re-saves.
            'delivered_at' => $becomingDelivered ? now() : $delivery->delivered_at,
            'received_by' => trim((string) ($data['received_by'] ?? '')) !== ''
                ? trim($data['received_by'])
                : $delivery->received_by,
            'confirmation_note' => trim((string) ($data['confirmation_note'] ?? '')) !== ''
                ? trim($data['confirmation_note'])
                : $delivery->confirmation_note,
        ]);
        $delivery->save();

        // PHASE 24 — every status change and reschedule hits the audit trail.
        if ($previousStatus !== $delivery->status) {
            // Only mention the failure reason when moving INTO failed, and the
            // receiver when moving INTO delivered — a stale detail on an
            // unrelated transition reads as if it caused it.
            $reasonNote = $delivery->status === 'failed' && $delivery->failureReasonLabel()
                ? ' — reason: '.$delivery->failureReasonLabel()
                : '';
            $receivedNote = $delivery->status === 'delivered' && $delivery->received_by
                ? ' — received by '.$delivery->received_by
                : '';
            $logger->log('delivery.status_changed', $delivery, sprintf(
                'Delivery %s for %s went "%s" → "%s"%s%s.',
                $delivery->id,
                $delivery->order?->order_number ?? '(no order)',
                Delivery::STATUSES[$previousStatus] ?? $previousStatus,
                $delivery->statusLabel(),
                $reasonNote,
                $receivedNote
            ), [
                'from_status' => $previousStatus,
                'to_status' => $delivery->status,
                'failure_reason' => $delivery->failure_reason,
                'received_by' => $delivery->received_by,
                'delivered_at' => $delivery->delivered_at?->toDateTimeString(),
            ]);
        }

        if ($delivery->status === 'failed'
            && $delivery->rescheduled_date
            && $delivery->rescheduled_date?->toDateString() !== $previousDate
            && $delivery->rescheduled_date?->toDateString() !== ($data['confirmed_date'] ?? null)) {
            $logger->log('delivery.rescheduled', $delivery, sprintf(
                'Delivery %s rescheduled to %s for %s.',
                $delivery->id,
                $delivery->rescheduled_date->format('d M Y'),
                $delivery->order?->order_number ?? '(no order)'
            ), ['new_date' => $delivery->rescheduled_date->toDateString()]);
        }

        $orderNote = $this->mirrorOrderStatus($delivery, $previousStatus);

        $notified = $this->notifyCustomer($delivery, $previousStatus, $dateChanged);

        $message = 'Delivery for '.$delivery->order->order_number.' updated'
            .' — status is now "'.$delivery->statusLabel().'".'.($notified ? ' Customer notified.' : '');

        if ($becomingDelivered && $delivery->delivered_at) {
            $message .= ' Delivered '.$delivery->delivered_at->format('d M Y, H:i')
                .' — received by '.($delivery->received_by ?: '—').'.';
        }

        if ($orderNote !== null) {
            $message .= ' '.$orderNote;
        }

        return redirect()
            ->route('admin.deliveries.show', $delivery)
            ->with('status', $message);
    }

    /* ------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------ */

    /**
     * Safety net: older delivery orders (placed before this screen existed)
     * still need a record, so create any that are missing.
     */
    private function reconcile(): void
    {
        Order::where('delivery_option', 'delivery')
            ->where('status', '!=', 'cancelled')
            ->whereDoesntHave('delivery')
            ->get()
            ->each(fn (Order $order) => $order->delivery()->create(['status' => 'pending']));
    }

    /**
     * Move the order along when the delivery status is also an order status.
     * Never forces the state machine - returns a note explaining what is left.
     */
    private function mirrorOrderStatus(Delivery $delivery, string $previousStatus): ?string
    {
        if ($previousStatus === $delivery->status) {
            return null;
        }

        $order = $delivery->order;
        $target = Delivery::ORDER_MAP[$delivery->status] ?? null;

        if ($order === null || $target === null) {
            return null; // pending / confirmed / failed have no order status
        }

        if ($order->status === $target) {
            return null;
        }

        if (! in_array($target, $order->allowedNextStatuses(), true)) {
            return 'Order status is still "'.$order->statusLabel().'" — open the order to move it there.';
        }

        $previous = $order->status;
        $order->status = $target;
        $order->save();

        $order->statusHistory()->create([
            'from_status' => $previous,
            'to_status' => $target,
            'changed_by' => auth()->id(),
            'notes' => 'Delivery marked "'.$delivery->statusLabel().'"',
        ]);

        if ($order->user) {
            $order->user->notify(new OrderStatusChanged(
                $order,
                Order::STATUSES[$previous] ?? ucfirst($previous),
                Order::STATUSES[$target] ?? ucfirst($target)
            ));
        }

        return null;
    }

    /** Tell the customer about a promised date or a failed attempt. */
    private function notifyCustomer(Delivery $delivery, string $previousStatus, bool $dateChanged): bool
    {
        $order = $delivery->order;

        if ($order === null || $order->user === null) {
            return false;
        }

        // out_for_delivery / delivered already notify through OrderStatusChanged.
        if ($delivery->status === 'confirmed' && $previousStatus !== 'confirmed' && $delivery->confirmed_date) {
            $order->user->notify(new DeliveryUpdated(
                $order,
                'Delivery Date Confirmed',
                'We have scheduled your delivery for order '.$order->order_number
                    .' on '.$delivery->confirmed_date->format('d M Y').'.'
            ));

            return true;
        }

        if ($delivery->status === 'failed' && $previousStatus !== 'failed') {
            // PHASE 21/22 — the customer hears what happened and what happens next.
            $reason = $delivery->failureReasonLabel();
            $next = $delivery->rescheduled_date
                ? 'A new date has been scheduled: '.$delivery->rescheduled_date->format('d M Y').'.'
                : 'We will contact you shortly to agree a new date.';

            $order->user->notify(new DeliveryUpdated(
                $order,
                'Delivery Rescheduled',
                'The delivery attempt for order '.$order->order_number
                    .($reason ? ' could not be completed ('.$reason.')' : ' could not be completed')
                    .' and has been rescheduled. '.$next
            ));

            return true;
        }

        if ($dateChanged && $delivery->confirmed_date && $previousStatus === $delivery->status) {
            $order->user->notify(new DeliveryUpdated(
                $order,
                'Delivery Date Updated',
                'The delivery date for order '.$order->order_number
                    .' is now '.$delivery->confirmed_date->format('d M Y').'.'
            ));

            return true;
        }

        return false;
    }
}
