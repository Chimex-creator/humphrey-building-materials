<?php

namespace App\Notifications;

use App\Models\PendingRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a guest who just registered, BEFORE any account exists.
 *
 * The link carries a signed, id + sha1(email) bound URL. Clicking it is
 * what creates the permanent customer account — the email really is the
 * gate, so this notification must go through the app's Gmail SMTP.
 */
class VerifyPendingRegistration extends Notification
{
    use Queueable;

    public function __construct(protected PendingRegistration $registration) {}

    /** Pending registrations have no database notification to write to. */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->registration->verificationUrl();

        return (new MailMessage)
            ->subject('Verify your email — Humphrey Building Materials')
            ->greeting('Hello '.$this->registration->name.'!')
            ->line('Thanks for registering with Humphrey Building Materials.')
            ->line('Please verify your email address to activate your account and start shopping.')
            ->action('Verify My Email', $url)
            ->line('This link expires in '.PendingRegistration::EXPIRE_MINUTES
                .' minutes — if it has expired, request a fresh one on the verification page.')
            ->line('If the button does not work, copy this link into your browser:')
            ->line($url)
            ->line('Humphrey Building Materials')
            ->line('Eda plaza beside abacha road mararaba, nasarawa')
            ->line('+2348153667923 · humphreybuildingmaterials@gmail.com');
    }
}
