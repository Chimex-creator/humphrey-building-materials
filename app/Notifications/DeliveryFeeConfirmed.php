<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the customer when staff confirms the delivery fee for their order. IN-APP ONLY — no email. */
class DeliveryFeeConfirmed extends Notification
{
    use Queueable;

    public function __construct(
        protected Order $order,
        protected float $fee
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Delivery Fee Confirmed',
            'message' => 'The delivery fee for order '.$this->order->order_number.' is ₦'.number_format($this->fee, 0)
                .'. Your new total is ₦'.number_format((float) $this->order->total, 0).'. You can now complete your payment.',
            'url' => route('orders.show', $this->order),
            'order' => $this->order->order_number,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Delivery fee confirmed — '.$this->order->order_number)
            ->line('The delivery fee for order '.$this->order->order_number.' has been confirmed at ₦'.number_format($this->fee, 0).'.')
            ->line('Your new total is ₦'.number_format((float) $this->order->total, 0).'.')
            ->line('You can now complete your payment.')
            ->action('Pay now', route('orders.payment', $this->order));
    }
}
