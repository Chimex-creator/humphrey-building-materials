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

        return redirect()
            ->route('support.show', $ticket)
            ->with('status', 'Thanks — your message has been received. Your reference is '
                .$ticket->reference.'. Our team will get back to you.');
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
