<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the customer whenever staff changes their order's status
 * (confirmed, ready for pickup, picked up, out for delivery, delivered,
 * cancelled, ...). Routine status changes are IN-APP ONLY — no email.
 */
class OrderStatusChanged extends Notification
{
    use Queueable;

    public function __construct(
        protected Order $order,
        protected string $fromLabel,
        protected string $toLabel
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $cancelled = $this->order->status === 'cancelled';

        return [
            'title' => $cancelled ? 'Order Cancelled' : 'Order Status Updated',
            'message' => 'Order '.$this->order->order_number.' moved from "'
                .$this->fromLabel.'" to "'.$this->toLabel.'".',
            'url' => route('orders.show', $this->order),
            'order' => $this->order->order_number,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Order '.$this->toLabel.' — '.$this->order->order_number)
            ->line('Your order '.$this->order->order_number.' status has changed.')
            ->line('Previous status: '.$this->fromLabel)
            ->line('New status: '.$this->toLabel);

        if ($this->order->status === 'cancelled') {
            $mail->line('Any stock reserved for this order has been returned to our shelves.');
        }

        return $mail->action('View order', route('orders.show', $this->order));
    }
}
