<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the customer when the delivery itself changes (Phase 7, §27):
 * confirmed, out for delivery, delivered, failed, rescheduled.
 * Routine delivery updates are IN-APP ONLY — no email.
 */
class DeliveryUpdated extends Notification
{
    use Queueable;

    public function __construct(
        protected Order $order,
        protected string $title,
        protected string $message
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
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
            ->subject($this->title.' - '.$this->order->order_number)
            ->line($this->message)
            ->action('View order', route('orders.show', $this->order));
    }
}
