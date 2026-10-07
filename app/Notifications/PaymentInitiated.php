<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent when a customer starts an online (Paystack) payment. */
class PaymentInitiated extends Notification
{
    use Queueable;

    public function __construct(
        protected Order $order,
        protected float $amount
    ) {}

    /**
     * database = in-app bell. Starting a payment is routine — IN-APP ONLY,
     * no email (only a successful payment/receipt emails the customer).
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Payment Started',
            'message' => 'You started a payment of ₦'.number_format($this->amount, 0).' for order '.$this->order->order_number.'.',
            'url' => route('orders.show', $this->order),
            'order' => $this->order->order_number,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Payment started — '.$this->order->order_number)
            ->line('We noticed you started a payment of ₦'.number_format($this->amount, 0).' for order '.$this->order->order_number.'.')
            ->line('If you did not start this payment, please ignore this message.')
            ->action('View order', route('orders.show', $this->order));
    }
}
