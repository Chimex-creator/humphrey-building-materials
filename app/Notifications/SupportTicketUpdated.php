<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 6 — the customer hears back when staff respond to (or change the
 * status of) their customer care record. The ticket itself is the source
 * of truth — this notification only reports what already happened.
 *
 * Customer care is IN-APP ONLY — no email.
 */
class SupportTicketUpdated extends Notification
{
    use Queueable;

    public function __construct(protected SupportTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $message = 'Update on '.$this->ticket->reference.': status is now '
            .$this->ticket->statusLabel().' — "'.$this->ticket->subject.'".';

        if ($this->ticket->staff_response) {
            $message .= ' We replied: '.mb_substr($this->ticket->staff_response, 0, 160);
        }

        return [
            'title' => 'Customer Care Update',
            'message' => $message,
            'url' => route('support.show', $this->ticket),
            'reference' => $this->ticket->reference,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Customer Care update — '.$this->ticket->reference)
            ->greeting('Hello '.$this->ticket->customer_name.'!')
            ->line('We have an update on your customer care request "'.$this->ticket->subject.'" ('
                .$this->ticket->reference.').')
            ->line('Current status: '.$this->ticket->statusLabel());

        if ($this->ticket->staff_response) {
            $mail->line('Our reply:')
                ->line($this->ticket->staff_response);
        }

        return $mail->action('View Request', route('support.show', $this->ticket))
            ->line('Humphrey Building Materials — +2348153667923');
    }
}
