<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Security alert: the account password was changed (by the account owner,
 * an admin, or a successful password reset).
 *
 * CRITICAL SECURITY EVENT → in-app + email (PART: email only for
 * critical/security/financial communication).
 */
class PasswordChanged extends Notification
{
    use Queueable;

    public function __construct(protected string $context = 'self') {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Password Changed',
            'message' => $this->sentence(),
            'url' => route('profile.edit'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your password was changed — Humphrey Building Materials')
            ->line($this->sentence())
            ->line('If this was not you, reset your password immediately or contact us on +2348153667923.')
            ->action('Review My Account', route('profile.edit'));
    }

    protected function sentence(): string
    {
        return match ($this->context) {
            'admin' => 'Your password was changed by an administrator.',
            'reset' => 'Your password has been reset successfully.',
            default => 'Your account password has been changed successfully.',
        };
    }
}
