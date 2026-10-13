<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessContact;
use App\Models\Setting;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Final Spec §24 — customer-care contacts are DB-driven.
 * Admin can add, edit, deactivate, reactivate, delete and bulk-manage
 * labelled contacts.
 */
class BusinessContactController extends Controller
{
    /** Every contact, active and inactive, newest order first. */
    public function index()
    {
        $contacts = BusinessContact::orderBy('contact_order')
            ->orderBy('id')
            ->get();

        $callToOrderContactId = (int) Setting::get(BusinessContact::CALL_TO_ORDER_SETTING, 0);

        return view('admin.contacts.index', compact('contacts', 'callToOrderContactId'));
    }

    /**
     * PHASE 5 — choose which contact's number the product page's
     * "Call to Order" action dials. Independent of the homepage's first
     * customer-care contact; NULL falls back to business_phone.
     */
    public function setCallToOrder(Request $request, ActivityLogger $logger)
    {
        $data = $request->validate([
            'contact_id' => ['nullable', 'integer', 'exists:business_contacts,id'],
        ]);

        $contactId = (int) ($data['contact_id'] ?? 0);

        if ($contactId > 0) {
            $contact = BusinessContact::findOrFail($contactId);
            if (! $contact->isActive()) {
                return back()->withErrors([
                    'contact_id' => '"'.$contact->label.'" is deactivated — reactivate it first, or choose the fallback.',
                ]);
            }
        }

        Setting::set(BusinessContact::CALL_TO_ORDER_SETTING, $contactId > 0 ? (string) $contactId : null);

        $logger->log('settings.updated', null, $contactId > 0
            ? 'Product "Call to Order" destination set to contact #'.$contactId.'.'
            : 'Product "Call to Order" destination reset to the business_phone fallback.');

        return back()->with('status', $contactId > 0
            ? 'Product enquiries now call the selected contact.'
            : 'Product enquiries now call the business phone number again.');
    }

    public function create()
    {
        return view('admin.contacts.create');
    }

    public function store(Request $request, ActivityLogger $logger)
    {
        $data = $this->validated($request);

        $contact = BusinessContact::create($data);

        $logger->log('contact.created', $contact, sprintf(
            'Customer-care contact "%s" (%s) added.',
            $contact->label,
            $contact->phone
        ), ['label' => $contact->label]);

        return redirect()->route('admin.contacts.index')
            ->with('status', 'Contact "'.$contact->label.'" added.');
    }

    public function edit(BusinessContact $contact)
    {
        return view('admin.contacts.edit', compact('contact'));
    }

    public function update(Request $request, BusinessContact $contact, ActivityLogger $logger)
    {
        $data = $this->validated($request);

        $contact->update($data);

        $logger->log('contact.updated', $contact, sprintf(
            'Customer-care contact "%s" updated (%s).',
            $contact->label,
            $contact->phone
        ), ['label' => $contact->label, 'status' => $contact->status]);

        return redirect()->route('admin.contacts.index')
            ->with('status', 'Contact "'.$contact->label.'" updated.');
    }

    /** Deactivate or reactivate in one press (§24). */
    public function toggle(BusinessContact $contact, ActivityLogger $logger)
    {
        $activating = $contact->status !== 'active';

        $contact->update(['status' => $activating ? 'active' : 'inactive']);

        // PHASE 5/10 — an inactive contact must never become the destination
        // of new calls; clear the selection so the fallback takes over.
        $wasCallToOrder = (int) Setting::get(BusinessContact::CALL_TO_ORDER_SETTING, 0) === $contact->id;
        if (! $activating && $wasCallToOrder) {
            Setting::set(BusinessContact::CALL_TO_ORDER_SETTING, null);
        }

        $logger->log($activating ? 'contact.activated' : 'contact.deactivated', $contact, sprintf(
            'Customer-care contact "%s" %s.',
            $contact->label,
            $activating ? 'reactivated' : 'deactivated'
        ));

        return back()->with(
            'status',
            'Contact "'.$contact->label.'" '.($activating ? 'reactivated.' : 'deactivated.')
            .(! $activating && $wasCallToOrder ? ' It was the product-enquiry number, so enquiries now use the business phone fallback.' : '')
        );
    }

    /**
     * Permanently delete one contact — deactivating keeps the row around,
     * deleting removes it from the list completely (still audited).
     */
    public function destroy(BusinessContact $contact, ActivityLogger $logger)
    {
        // PHASE 5 — deleting the selected product-enquiry contact falls back.
        $wasCallToOrder = (int) Setting::get(BusinessContact::CALL_TO_ORDER_SETTING, 0) === $contact->id;
        if ($wasCallToOrder) {
            Setting::set(BusinessContact::CALL_TO_ORDER_SETTING, null);
        }

        $logger->log('contact.deleted', $contact, sprintf(
            'Customer-care contact "%s" (%s) deleted.',
            $contact->label,
            $contact->phone
        ), ['label' => $contact->label, 'phone' => $contact->phone]);

        $contact->delete();

        return redirect()->route('admin.contacts.index')
            ->with('status', 'Contact "'.$contact->label.'" deleted.');
    }

    /**
     * Final Spec §39 — bulk activate / deactivate / delete contacts.
     *
     * Only rows that actually change are counted, so the flash message is
     * always honest, and every run writes one audit line.
     */
    public function bulk(Request $request, ActivityLogger $logger)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate', 'delete'])],
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['integer', 'exists:business_contacts,id'],
        ]);

        if ($data['action'] === 'delete') {
            $contacts = BusinessContact::whereIn('id', $data['contact_ids'])->get();
            $count = $contacts->count();

            if ($count > 0) {
                BusinessContact::whereIn('id', $contacts->pluck('id'))->delete();

                $logger->log('contacts.bulk_delete', null, sprintf(
                    '%d customer-care contact(s) deleted in one bulk action.',
                    $count
                ), [
                    'count' => $count,
                    'ids' => $contacts->pluck('id')->all(),
                ]);
            }

            $this->clearCallToOrderIfUnavailable();

            return back()->with('status', $count > 0
                ? $count.' contact(s) deleted.'
                : 'Nothing to delete.');
        }

        $status = $data['action'] === 'activate' ? 'active' : 'inactive';

        $changed = BusinessContact::whereIn('id', $data['contact_ids'])
            ->where('status', '!=', $status)
            ->update(['status' => $status]);

        if ($changed > 0) {
            $logger->log('contacts.bulk_'.$data['action'], null, sprintf(
                '%d customer-care contact(s) %s in one bulk action.',
                $changed,
                $status === 'active' ? 'activated' : 'deactivated'
            ), [
                'count' => $changed,
                'ids' => array_map('intval', $data['contact_ids']),
            ]);
        }

        $this->clearCallToOrderIfUnavailable();

        return back()->with('status', $changed > 0
            ? $changed.' contact(s) marked as '.$status.'.'
            : 'Nothing to change — the selected contacts were already '.$status.'.');
    }

    /**
     * PHASE 5 — if the product-enquiry contact was deactivated or deleted
     * (by any path), reset the setting so the owner-number fallback applies.
     */
    private function clearCallToOrderIfUnavailable(): void
    {
        $selectedId = (int) Setting::get(BusinessContact::CALL_TO_ORDER_SETTING, 0);

        if ($selectedId > 0 && ! BusinessContact::active()->whereKey($selectedId)->exists()) {
            Setting::set(BusinessContact::CALL_TO_ORDER_SETTING, null);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'phone' => ['required', 'string', 'max:30'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'contact_order' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
    }
}
