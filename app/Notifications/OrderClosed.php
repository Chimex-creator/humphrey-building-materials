<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** PHASE 10 — the order has been closed out and is finished. */
class OrderClosed extends Notification
{
    use Queueable;

    public function __construct(protected Order $order) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Order Closed',
            'message' => 'Order '.$this->order->order_number
                .' has been closed. Thank you for shopping with us.',
            'url' => route('orders.show', $this->order),
            'order' => $this->order->order_number,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Order closed — '.$this->order->order_number)
            ->line('Your order '.$this->order->order_number.' has been closed.')
            ->line('Paid: ₦'.number_format($this->order->paidAmount(), 0))
            ->line('We hope to see you again soon.')
            ->action('View order', route('orders.show', $this->order));
    }
}
