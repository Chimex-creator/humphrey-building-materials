<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Phase 6 — in-app alert for staff when a customer files a complaint.
 * Staff-only (database channel): no email flood for the support inbox.
 */
class SupportTicketCreated extends Notification
{
    use Queueable;

    public function __construct(protected SupportTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'New Customer Care Message',
            'message' => $this->ticket->customer_name.' opened "'.$this->ticket->subject.'" ('
                .$this->ticket->categoryLabel().') — reference '.$this->ticket->reference.'.',
            'url' => route('admin.support.show', $this->ticket),
            'reference' => $this->ticket->reference,
        ];
    }
}
