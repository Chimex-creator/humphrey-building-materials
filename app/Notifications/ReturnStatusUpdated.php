<?php

namespace App\Notifications;

use App\Models\ProductReturn;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * PHASE 10 — tells the customer their return progressed (reviewed, approved
 * or rejected). Routine return progress is IN-APP ONLY — no email.
 */
class ReturnStatusUpdated extends Notification
{
    use Queueable;

    public function __construct(protected ProductReturn $return) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function approved(): bool
    {
        return $this->return->isApproved();
    }

    public function toArray(object $notifiable): array
    {
        $product = $this->return->product;
        $order = $this->return->order;

        return [
            'title' => $this->approved() ? 'Return Approved' : 'Return Rejected',
            'message' => 'Your return of '.$this->return->quantity.' × '
                .($product->name ?? 'item')
                .($order ? ' on order '.$order->order_number : '')
                .' has been '.($this->approved() ? 'approved' : 'rejected').'.'
                .($this->approved() ? ' Bring or send the goods to us — once received and inspected they go back into stock.' : ''),
            'url' => $order ? route('orders.show', $order) : route('orders.index'),
            'order' => $order?->order_number,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->return->order;
        $product = $this->return->product;

        $mail = (new MailMessage)
            ->subject(($this->approved() ? 'Return approved' : 'Return rejected')
                .($order ? ' — '.$order->order_number : ''))
            ->line('Return of '.$this->return->quantity.' × '.($product->name ?? 'item')
                .' has been '.($this->approved() ? 'approved' : 'rejected').'.')
            ->line('Reason given: '.$this->return->reason);

        if ($this->approved()) {
            $mail->line('Once the goods are back with us they will be inspected and the '
                .'resellable units will go straight back into stock.');
        }

        return $order ? $mail->action('View order', route('orders.show', $order)) : $mail;
    }
}
