<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Get a setting value by key.
     * Usage: Setting::get('business_name', 'Humphrey Building Materials')
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $setting = static::where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }

    /**
     * Get a setting as a positive integer (falls back to $default when the
     * value is missing or not a usable number).
     * Usage: Setting::int('low_stock_threshold', 10)
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = static::get($key);

        if ($value === null || ! is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * Save (or update) a setting.
     * Usage: Setting::set('business_name', 'New Name')
     */
    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
