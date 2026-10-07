<?php

namespace App\Models;

use App\Notifications\VerifyPendingRegistration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\URL;

/**
 * A registration that has been submitted but not yet email-verified.
 *
 * There is deliberately no User row until verification succeeds, so the
 * account genuinely does not exist for login/authorization purposes.
 * The row is deleted the moment the signed verification link is used
 * (or when an existing account claims it), completing the registration.
 */
class PendingRegistration extends Model
{
    use Notifiable;

    /** How long a verification link stays valid (60 minutes, like password reset). */
    public const EXPIRE_MINUTES = 60;

    protected $fillable = [
        'name',
        'email',
        'password',
        'expires_at',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed', // bcrypt on write; already-hashed values pass through
            'expires_at' => 'datetime',
        ];
    }

    /** Fresh expiry timestamp for a new or re-sent link. */
    public static function expiry()
    {
        return now()->addMinutes(self::EXPIRE_MINUTES);
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    /** Absolute signed URL that completes the registration. */
    public function verificationUrl(): string
    {
        return URL::signedRoute('registration.verify', [
            'id' => $this->id,
            'hash' => sha1($this->email),
        ]);
    }

    /** Send (or re-send) the real verification email via the app's SMTP. */
    public function sendVerificationEmail(): void
    {
        $this->notify(new VerifyPendingRegistration($this));
    }
}
