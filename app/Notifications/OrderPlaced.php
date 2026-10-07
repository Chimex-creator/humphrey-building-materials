<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a verified payment automatically confirms an order (Final
 * Spec §20) — never at checkout time.
 *
 *  - the CUSTOMER gets in-app + email confirmation
 *  - SALES/ADMIN staff get an in-app "new order" alert only (no email flood)
 */
class OrderPlaced extends Notification
{
    use Queueable;

    public function __construct(
        protected Order $order,
        protected bool $staffNotice = false
    ) {}

    public function via(object $notifiable): array
    {
        return $this->staffNotice ? ['database'] : ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        if ($this->staffNotice) {
            return [
                'title' => 'New Order Received',
                'message' => $this->order->customer_name.' placed order '.$this->order->order_number
                    .' ('.$this->order->deliveryOptionLabel().') worth ₦'.number_format((float) $this->order->total, 0).'.',
                'url' => route('admin.orders.show', $this->order),
                'order' => $this->order->order_number,
            ];
        }

        return [
            'title' => 'Order Confirmed',
            'message' => 'We have received your order '.$this->order->order_number
                .' ('.$this->order->deliveryOptionLabel().') worth ₦'.number_format((float) $this->order->total, 0).'.',
            'url' => route('orders.show', $this->order),
            'order' => $this->order->order_number,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Order confirmed — '.$this->order->order_number)
            ->line('Thank you, '.$this->order->customer_name.'. Your payment was received and your order is confirmed.')
            ->line('Order number: '.$this->order->order_number)
            ->line('Items subtotal: ₦'.number_format((float) $this->order->subtotal, 0));

        if ($this->order->isDelivery()) {
            $mail->line('Delivery to: '.$this->order->delivery_address)
                ->line('Our team will contact you to arrange the delivery.');
        } else {
            $mail->line('Pickup from our shop — no delivery fee applies.')
                ->line('We will notify you as soon as your order is ready for pickup.');
        }

        return $mail->action('View order', route('orders.show', $this->order));
    }
}
