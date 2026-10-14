<?php

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class BrevoApiTransport extends AbstractTransport
{
    public function __construct(private readonly ?string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if (blank($this->apiKey)) {
            throw new RuntimeException('Brevo API key is not configured.');
        }

        $email = $message->getOriginalMessage();

        if (! $email instanceof Email) {
            throw new RuntimeException(
                'Brevo transport received an unsupported email message.'
            );
        }

        $from = $email->getFrom()[0] ?? null;

        if (! $from) {
            throw new RuntimeException('The email sender is missing.');
        }

        $payload = [
            'sender' => $this->formatAddress($from),
            'to' => array_map(
                fn (Address $address) => $this->formatAddress($address),
                $email->getTo()
            ),
            'subject' => $email->getSubject() ?? '',
        ];

        if ($email->getCc() !== []) {
            $payload['cc'] = array_map(
                fn (Address $address) => $this->formatAddress($address),
                $email->getCc()
            );
        }

        if ($email->getBcc() !== []) {
            $payload['bcc'] = array_map(
                fn (Address $address) => $this->formatAddress($address),
                $email->getBcc()
            );
        }

        if ($email->getReplyTo() !== []) {
            $payload['replyTo'] = $this->formatAddress(
                $email->getReplyTo()[0]
            );
        }

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();

        if (is_string($html) && $html !== '') {
            $payload['htmlContent'] = $html;
        } elseif (is_string($text) && $text !== '') {
            $payload['textContent'] = $text;
        } else {
            throw new RuntimeException('The email has no message content.');
        }

        $response = Http::acceptJson()
            ->withHeaders(['api-key' => $this->apiKey])
            ->timeout(20)
            ->post('https://api.brevo.com/v3/smtp/email', $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Brevo rejected the email request (HTTP '
                . $response->status() . '). Check the Brevo transactional logs.'
            );
        }
    }

    private function formatAddress(Address $address): array
    {
        $formatted = ['email' => $address->getAddress()];

        if ($address->getName() !== '') {
            $formatted['name'] = $address->getName();
        }

        return $formatted;
    }

    public function __toString(): string
    {
        return 'brevo_api';
    }
}
