<?php

namespace App\Notifications;

use App\Models\ProductReturn;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * PHASE 10 — in-app alert for staff when a customer asks to return something.
 * In-app only, so a burst of requests does not flood anyone's inbox.
 */
class ReturnRequested extends Notification
{
    use Queueable;

    public function __construct(protected ProductReturn $return) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $product = $this->return->product;
        $order = $this->return->order;

        return [
            'title' => 'Return Requested',
            'message' => ($order ? $order->customer_name.' on order '.$order->order_number : 'A customer')
                .' asked to return '.$this->return->quantity.' × '
                .($product->name ?? 'a product').'. Awaiting review.',
            'url' => route('admin.returns.index', ['status' => 'pending']),
            'order' => $order?->order_number,
        ];
    }
}
