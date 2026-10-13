<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Notifications\SupportTicketUpdated;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

/**
 * Phase 6 — Customer Care for staff (admin / sales).
 *
 * Read, respond and move a complaint through its real lifecycle:
 * open → in progress → resolved (Final Spec §42 — three statuses).
 * Resolving is always a deliberate staff decision — never an automatic
 * side effect.
 */
class SupportController extends Controller
{
    /** Filterable list of every customer care record. */
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:20', 'in:'.implode(',', array_keys(SupportTicket::STATUSES))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = SupportTicket::query()->with('customer', 'order');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        $search = trim((string) ($data['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }

        $tickets = $query->latest()->paginate(20)->withQueryString();

        $counts = SupportTicket::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.support.index', compact('tickets', 'counts', 'data'));
    }

    /** One record with its full context. */
    public function show(SupportTicket $ticket)
    {
        $ticket->load(['customer', 'order', 'product', 'responder']);

        return view('admin.support.show', compact('ticket'));
    }

    /** Respond and/or change the status. */
    public function update(Request $request, SupportTicket $ticket, ActivityLogger $logger)
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(SupportTicket::STATUSES))],
            'staff_response' => ['nullable', 'string', 'max:5000'],
        ]);

        $previousStatus = $ticket->status;
        $previousResponse = $ticket->staff_response;
        $response = trim((string) ($data['staff_response'] ?? ''));
        $statusChanged = $data['status'] !== $previousStatus;
        $responseChanged = $response !== '' && $response !== $previousResponse;

        if (! $statusChanged && ! $responseChanged) {
            return back()->with('error', 'Nothing changed — write a response or pick a new status.');
        }

        $changes = [
            'status' => $data['status'],
            'staff_response' => $response !== '' ? $response : $ticket->staff_response,
        ];

        if ($responseChanged) {
            $changes['responded_by'] = auth()->id();
            $changes['responded_at'] = now();
        }

        if ($statusChanged
            && $data['status'] === 'resolved'
            && ! $ticket->resolved_at) {
            $changes['resolved_at'] = now();
        }

        $ticket->update($changes);

        $logger->log('support.updated', $ticket,
            'Customer care request '.$ticket->reference.' moved from '
            .SupportTicket::STATUSES[$previousStatus].' to '.$ticket->statusLabel().'.',
            [
                'from_status' => $previousStatus,
                'to_status' => $ticket->status,
                'responded' => $responseChanged,
            ]);

        // Tell the customer what already happened — the record changed first.
        if ($statusChanged || $responseChanged) {
            $ticket->customer?->notify(new SupportTicketUpdated($ticket));
        }

        return redirect()
            ->route('admin.support.show', $ticket)
            ->with('status', 'Customer care request '.$ticket->reference.' updated.');
    }

    /**
     * PHASE 16 — bulk move eligible tickets to In Progress or Resolved.
     * Same rules as the single form minus the free-text response (bulk has
     * no response box): open → in_progress, open|in_progress → resolved.
     * No "Closed" status exists; nothing is resolved automatically.
     */
    public function bulk(Request $request, ActivityLogger $logger)
    {
        $data = $request->validate([
            'action' => ['required', 'in:start_progress,resolve'],
            'ticket_ids' => ['required', 'array', 'min:1'],
            'ticket_ids.*' => ['integer', 'exists:support_tickets,id'],
        ]);

        $tickets = SupportTicket::with('customer')
            ->whereIn('id', $data['ticket_ids'])
            ->get();

        $changed = 0;
        $skipped = [];

        foreach ($tickets as $ticket) {
            $previousStatus = $ticket->status;

            if ($data['action'] === 'start_progress') {
                if ($previousStatus !== 'open') {
                    $skipped[] = $ticket->reference.': only open tickets can start in progress.';

                    continue;
                }
                $ticket->update(['status' => 'in_progress']);
            } else {
                if ($previousStatus === 'resolved') {
                    $skipped[] = $ticket->reference.': already resolved.';

                    continue;
                }
                $ticket->update([
                    'status' => 'resolved',
                    'resolved_at' => $ticket->resolved_at ?? now(),
                ]);
            }

            $logger->log('support.updated', $ticket,
                'Customer care request '.$ticket->reference.' moved from '
                .SupportTicket::STATUSES[$previousStatus].' to '.$ticket->statusLabel().' (bulk).',
                [
                    'from_status' => $previousStatus,
                    'to_status' => $ticket->status,
                    'via' => 'bulk',
                ]);

            $ticket->customer?->notify(new SupportTicketUpdated($ticket));
            $changed++;
        }

        if ($changed > 0) {
            $logger->log(
                $data['action'] === 'start_progress' ? 'support.bulk_progress' : 'support.bulk_resolve',
                null,
                sprintf('%d support ticket(s) updated in one bulk action.', $changed),
                ['count' => $changed, 'action' => $data['action']]
            );
        }

        $message = $changed > 0
            ? $changed.' ticket(s) updated.'
            : 'No tickets were changed.';

        if ($skipped !== []) {
            $message .= ' Skipped '.count($skipped).': '.implode(' | ', array_slice($skipped, 0, 5))
                .(count($skipped) > 5 ? ' …' : '');
        }

        return back()->with($changed > 0 ? 'status' : 'error', $message);
    }
}
