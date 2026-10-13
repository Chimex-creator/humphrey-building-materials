<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Final Spec §24 — customer-care contact numbers.
 *
 * Never hard-coded: admins add/edit/deactivate/reactivate labelled contacts
 * (Customer Care, Sales Support, ...) and every public "Call Us" section
 * shows only the ACTIVE rows, in the admin-chosen order.
 */
class BusinessContact extends Model
{
    /**
     * PHASE 5 — settings key holding the contact id whose phone powers the
     * product page "Call to Order" action. NULL = fall back to the owner
     * number in `business_phone` (the safe default before this feature).
     */
    public const CALL_TO_ORDER_SETTING = 'product_call_to_order_contact_id';

    protected $fillable = [
        'label',
        'phone',
        'status',
        'contact_order',
    ];

    /**
     * PHASE 5 — the phone the product-detail "Call to Order" button dials.
     *
     * Admin-selected active contact first; otherwise the business_phone
     * setting (owner number). An inactive or deleted selected contact is
     * never dialed — it silently falls back so the action keeps working.
     */
    public static function callToOrderPhone(): string
    {
        $selectedId = (int) Setting::get(self::CALL_TO_ORDER_SETTING, 0);

        if ($selectedId > 0) {
            $contact = self::active()->find($selectedId);
            if ($contact) {
                return (string) $contact->phone;
            }
        }

        return (string) Setting::get('business_phone', '+2348153667923');
    }

    /** Currently selected product-enquiry contact, or null for the fallback. */
    public static function callToOrderContact(): ?self
    {
        $selectedId = (int) Setting::get(self::CALL_TO_ORDER_SETTING, 0);

        return $selectedId > 0 ? self::active()->find($selectedId) : null;
    }

    /** Only these rows ever reach a public page. */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function statusLabel(): string
    {
        return $this->status === 'active' ? 'Active' : 'Inactive';
    }

    /** Diallable form: keeps + and digits only. */
    public function telHref(): string
    {
        return 'tel:'.preg_replace('/[^0-9+]/', '', (string) $this->phone);
    }
}
