<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    /** Show business settings form. */
    public function edit()
    {
        return view('admin.settings', [
            'settings' => $this->allSettings(),
        ]);
    }

    /** Save business settings + optional logo upload. */
    public function update(Request $request, ActivityLogger $logger)
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'business_email' => ['required', 'email', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:30'],
            'business_address' => ['nullable', 'string', 'max:500'],
            'receipt_note' => ['nullable', 'string', 'max:500'],
            'low_stock_threshold' => ['required', 'integer', 'min:1', 'max:100000'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'], // max 2MB
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        // PHASE 12 — snapshot of the settings before they change (Master §47).
        $before = [
            'business_name' => Setting::get('business_name', config('app.name')),
            'business_email' => Setting::get('business_email', 'humphreybuildingmaterials@gmail.com'),
            'business_phone' => Setting::get('business_phone', ''),
            'business_address' => Setting::get('business_address', ''),
            'receipt_note' => Setting::get('receipt_note', ''),
            'low_stock_threshold' => (string) Setting::int('low_stock_threshold', 10),
        ];

        // 1. Handle logo upload / removal
        if ($request->boolean('remove_logo')) {
            $this->deleteLogo();
            Setting::set('logo_path', null);
        } elseif ($request->hasFile('logo')) {
            // Delete old logo first (replace)
            $this->deleteLogo();

            // store() saves to storage/app/public/logos/ and returns the path
            $path = $request->file('logo')->store('logos', 'public');
            Setting::set('logo_path', $path);
        }

        // 2. Save text settings
        Setting::set('business_name', $data['business_name']);
        Setting::set('business_email', $data['business_email']);
        Setting::set('business_phone', $data['business_phone'] ?? null);
        Setting::set('business_address', $data['business_address'] ?? null);
        Setting::set('receipt_note', $data['receipt_note'] ?? null);
        Setting::set('low_stock_threshold', (string) $data['low_stock_threshold']);

        // PHASE 12 — only the values that actually changed are written to the log.
        $after = [
            'business_name' => $data['business_name'],
            'business_email' => $data['business_email'],
            'business_phone' => $data['business_phone'] ?? '',
            'business_address' => $data['business_address'] ?? '',
            'receipt_note' => $data['receipt_note'] ?? '',
            'low_stock_threshold' => (string) $data['low_stock_threshold'],
        ];

        $changes = [];
        foreach ($before as $key => $old) {
            if ((string) ($old ?? '') !== (string) ($after[$key] ?? '')) {
                $changes[$key] = ['from' => (string) ($old ?? ''), 'to' => (string) ($after[$key] ?? '')];
            }
        }

        if ($request->boolean('remove_logo')) {
            $changes['logo'] = ['from' => 'current logo', 'to' => 'removed'];
        } elseif ($request->hasFile('logo')) {
            $changes['logo'] = ['from' => 'current logo', 'to' => 'replaced'];
        }

        if ($changes !== []) {
            $logger->log('settings.updated', null, 'Business settings updated: '.implode(', ', array_keys($changes)).'.', $changes);
        }

        return redirect()->route('admin.settings')->with('status', 'Business settings saved successfully.');
    }

    /** Current settings as an array. */
    private function allSettings(): array
    {
        return [
            'business_name' => Setting::get('business_name', config('app.name')),
            'business_email' => Setting::get('business_email', 'humphreybuildingmaterials@gmail.com'),
            'business_phone' => Setting::get('business_phone', ''),
            'business_address' => Setting::get('business_address', ''),
            'receipt_note' => Setting::get('receipt_note', ''),
            'low_stock_threshold' => (string) Setting::int('low_stock_threshold', 10),
            'logo_path' => Setting::get('logo_path'),
        ];
    }

    /** Delete the current logo file if it exists. */
    private function deleteLogo(): void
    {
        $current = Setting::get('logo_path');
        if ($current && Storage::disk('public')->exists($current)) {
            Storage::disk('public')->delete($current);
        }
    }
}
