<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Slack;

use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use Psr\Http\Message\ServerRequestInterface;

class SlackWebhookVerifier
{
    private const TIMESTAMP_TOLERANCE = 300; // 5 minutes

    public function __construct(
        private readonly string $signingSecret,
    ) {}

    public function verify(ServerRequestInterface $request, string $body): void
    {
        $timestamp = $request->getHeaderLine('X-Slack-Request-Timestamp');

        if ($timestamp === '' || abs(time() - (int) $timestamp) > self::TIMESTAMP_TOLERANCE) {
            throw new AuthenticationException('Invalid or expired Slack webhook timestamp');
        }

        $signature = $request->getHeaderLine('X-Slack-Signature');

        if ($signature === '') {
            throw new AuthenticationException('Missing X-Slack-Signature header');
        }

        $expected = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", $this->signingSecret);

        if (! hash_equals($expected, $signature)) {
            throw new AuthenticationException('Invalid Slack webhook signature');
        }
    }
}
