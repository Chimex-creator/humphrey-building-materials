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
    protected $fillable = [
        'label',
        'phone',
        'status',
        'contact_order',
    ];

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
