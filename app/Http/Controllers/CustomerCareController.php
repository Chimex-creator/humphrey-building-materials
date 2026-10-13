<?php

namespace App\Http\Controllers;

use App\Models\BusinessContact;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketCreated;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

/**
 * Phase 6 — Customer Care (customer side).
 *
 * A structured way to report problems and enquiries: contact details and
 * a call button sit next to the form, and every submission becomes a
 * support record that staff manage to a real conclusion.
 */
class CustomerCareController extends Controller
{
    /** Categories that report a problem — they get complaint wording. */
    private const COMPLAINT_CATEGORIES = [
        'order_issue',
        'delivery_issue',
        'product_issue',
        'payment_issue',
        'return_issue',
    ];

    /** How long an identical repeat submission counts as a double-send. */
    private const DUPLICATE_WINDOW_MINUTES = 1;

    /** The customer care page: contact info, complaint form, own records. */
    public function index(Request $request)
    {
        $tickets = SupportTicket::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(10);

        return view('customer-care.index', [
            'tickets' => $tickets,
            'categories' => SupportTicket::CATEGORIES,
            'products' => Product::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            // Final Spec §24 — labelled contacts from the DB (fallback = settings).
            'contacts' => BusinessContact::active()->orderBy('contact_order')->orderBy('id')->get(),
            'phone' => Setting::get('business_phone', '+2348153667923'),
            'email' => Setting::get('business_email', 'humphreybuildingmaterials@gmail.com'),
            'address' => Setting::get('business_address', 'Eda plaza beside abacha road mararaba, nasarawa'),
        ]);
    }

    /** File a new complaint / enquiry. */
    public function store(Request $request, ActivityLogger $logger)
    {
        $user = $request->user();

        $data = $request->validate([
            'category' => ['required', 'string', 'max:40', 'in:'.implode(',', array_keys(SupportTicket::CATEGORIES))],
            'subject' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'order_reference' => ['nullable', 'string', 'max:30'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
        ]);

        // The order reference must point at ONE OF THE CUSTOMER'S OWN orders —
        // never someone else's, and never a made-up number.
        $order = null;
        if (! empty($data['order_reference'])) {
            $order = Order::where('order_number', $data['order_reference'])
                ->where('user_id', $user->id)
                ->first();

            if (! $order) {
                return back()
                    ->withErrors(['order_reference' => 'We could not find that order on your account. '
                        .'Leave it blank for general enquiries, or copy the order number exactly.'])
                    ->withInput();
            }
        }

        // An accidental repeat (double-click, or the back button followed by
        // "send" again) must not open a second record of the same message.
        $duplicate = SupportTicket::where('user_id', $user->id)
            ->where('category', $data['category'])
            ->where('subject', $data['subject'])
            ->where('description', $data['description'])
            ->where('created_at', '>=', now()->subMinutes(self::DUPLICATE_WINDOW_MINUTES))
            ->latest('id')
            ->first();

        if ($duplicate) {
            return $this->submitted($duplicate);
        }

        $ticket = SupportTicket::create([
            'reference' => 'TMP-'.uniqid(),
            'user_id' => $user->id,
            'order_id' => $order?->id,
            'product_id' => $data['product_id'] ?? null,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'customer_phone' => (($data['customer_phone'] ?? '') !== '' ? $data['customer_phone'] : $user->phone),
            'category' => $data['category'],
            'subject' => $data['subject'],
            'description' => $data['description'],
            'status' => 'open',
        ]);

        $ticket->update([
            'reference' => 'SUP-'.now()->year.'-'.str_pad($ticket->id, 6, '0', STR_PAD_LEFT),
        ]);

        // Alert the staff who handle customer care (in-app only).
        User::whereIn('role', ['admin', 'sales'])
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->get()
            ->each(fn (User $staff) => $staff->notify(new SupportTicketCreated($ticket)));

        $logger->log('support.created', $ticket,
            'Customer care request '.$ticket->reference.' opened by '.$ticket->customer_name.'.',
            ['category' => $ticket->category, 'subject' => $ticket->subject]);

        return $this->submitted($ticket);
    }

    /**
     * Send the customer to their record with the professional confirmation.
     *
     * The wording is only ever built here — after the record exists — so a
     * failed validation (which never reaches this method) can never show a
     * success message.
     */
    private function submitted(SupportTicket $ticket)
    {
        $isComplaint = in_array($ticket->category, self::COMPLAINT_CATEGORIES, true);

        $message = $isComplaint
            ? 'Thank you for bringing this to our attention. Your complaint has been received, '
                .'and our customer care team will review it and get back to you as soon as possible.'
            : 'Thank you for contacting Humphrey Building Materials. We have received your message '
                .'and our customer care team will review your request and get back to you as soon as possible.';

        $reference = $ticket->reference;

        return redirect()
            ->route('support.show', $ticket)
            // Plain-text echo of the same confirmation (kept for the flash
            // message contract) …
            ->with('status', 'Message Submitted Successfully. '.$message
                .' Your reference is '.$reference.' — please keep it safe.')
            // … and the structured payload the confirmation panel renders.
            ->with('submission', [
                'kind' => $isComplaint ? 'complaint' : 'message',
                'title' => 'Message Submitted Successfully',
                'message' => $message,
                'reference' => $reference,
            ]);
    }

    /** View one of the customer's own support records (ownership enforced). */
    public function show(Request $request, SupportTicket $ticket)
    {
        if ((int) $ticket->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        return view('customer-care.show', compact('ticket'));
    }
}
