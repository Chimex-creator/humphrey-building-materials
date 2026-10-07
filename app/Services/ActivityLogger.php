<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * PHASE 12 — writes the audit trail (Master Prompt §47).
 *
 * Recording who changed stock, prices, payments, orders, staff accounts and
 * settings, with the record, the date/time and the changed values.
 *
 * Writing a log line must never break the business action it describes, so
 * failures are reported to the log file instead of being thrown.
 */
class ActivityLogger
{
    public function log(string $action, ?Model $subject, string $description, array $details = []): void
    {
        try {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => $action,
                'subject_type' => $subject === null ? null : $subject::class,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $this->label($subject),
                'description' => $description,
                'details' => $details === [] ? null : $details,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Activity log write failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Snapshot of the record's human-readable identity. */
    protected function label(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        foreach (['order_number', 'name', 'reference', 'email', 'title', 'label'] as $field) {
            $value = $subject->getAttribute($field);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '#'.$subject->getKey();
    }
}
