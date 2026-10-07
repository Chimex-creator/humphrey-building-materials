<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One class for every payment outcome: success and failure (Final Spec §18 —
 * Paystack only).
 *
 * CHANNEL ROUTING: in-app for every outcome; email ONLY when this is a real
 * money receipt ($emailReceipt = true — successful payment). Failures are
 * routine IN-APP ONLY — no email. NO SMS.
 */
class PaymentStatusUpdated extends Notification
{
    use Queueable;

    public function __construct(
        protected string $title,
        protected string $message,
        protected Order $order,
        protected bool $emailReceipt = false
    ) {}

    public function via(object $notifiable): array
    {
        return $this->emailReceipt ? ['database', 'mail'] : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'url' => route('orders.show', $this->order),
            'order' => $this->order->order_number,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title.' — '.$this->order->order_number)
            ->line($this->message)
            ->action('View order', route('orders.show', $this->order));
    }
}
