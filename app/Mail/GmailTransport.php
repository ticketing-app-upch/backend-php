<?php

namespace App\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class GmailTransport extends AbstractTransport
{
    public function __construct(
        private string $clientId,
        #[SensitiveParameter] private string $clientSecret,
        #[SensitiveParameter] private string $refreshToken,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'gmail-api';
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->clientId === '' || $this->clientSecret === '' || $this->refreshToken === '') {
            throw new TransportException('Gmail OAuth credentials are incomplete.');
        }

        $client = Http::acceptJson()->withoutRedirecting()->connectTimeout(2)->timeout(5);

        try {
            $tokenResponse = (clone $client)->asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $this->refreshToken,
                'grant_type' => 'refresh_token',
            ]);
            $accessToken = $tokenResponse->json('access_token');
            if (! $tokenResponse->successful() || ! is_string($accessToken) || $accessToken === '') {
                throw new TransportException('Gmail OAuth token exchange failed.', $tokenResponse->status());
            }

            $sender = rawurlencode($message->getEnvelope()->getSender()->getAddress());
            $raw = rtrim(strtr(base64_encode($message->getOriginalMessage()->toString()), '+/', '-_'), '=');
            $response = $client->withToken($accessToken)->post(
                "https://gmail.googleapis.com/gmail/v1/users/{$sender}/messages/send",
                ['raw' => $raw],
            );
        } catch (ConnectionException) {
            throw new TransportException('Unable to connect to the Gmail API.');
        }

        $messageId = $response->json('id');
        if (! $response->successful() || ! is_string($messageId) || $messageId === '') {
            throw new TransportException('Gmail API did not accept the message.', $response->status());
        }

        $message->setMessageId($messageId);
    }
}
