<?php

namespace BootDesk\ChatSDK\Slack\Tests;

use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Slack\SlackWebhookVerifier;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

class SlackWebhookVerifierTest extends TestCase
{
    private SlackWebhookVerifier $verifier;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->verifier = new SlackWebhookVerifier('test_signing_secret');
        $this->factory = new Psr17Factory;
    }

    public function test_valid_signature_passes(): void
    {
        $timestamp = (string) time();
        $body = '{"type":"event_callback","event":{"type":"message","text":"hello"}}';
        $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'test_signing_secret');

        $request = $this->createRequest($body, $timestamp, $signature);

        $this->verifier->verify($request, $body);
        $this->assertTrue(true); // No exception thrown
    }

    public function test_invalid_signature_throws(): void
    {
        $timestamp = (string) time();
        $body = '{"type":"event_callback"}';

        $request = $this->createRequest($body, $timestamp, 'v0=invalid_signature');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid Slack webhook signature');

        $this->verifier->verify($request, $body);
    }

    public function test_expired_timestamp_throws(): void
    {
        $oldTimestamp = (string) (time() - 600);
        $body = '{"type":"event_callback"}';
        $signature = 'v0='.hash_hmac('sha256', "v0:{$oldTimestamp}:{$body}", 'test_signing_secret');

        $request = $this->createRequest($body, $oldTimestamp, $signature);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('timestamp');

        $this->verifier->verify($request, $body);
    }

    public function test_missing_signature_throws(): void
    {
        $body = '{"type":"event_callback"}';
        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('X-Slack-Request-Timestamp', (string) time())
            ->withBody($this->factory->createStream($body));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Missing X-Slack-Signature');

        $this->verifier->verify($request, $body);
    }

    public function test_missing_timestamp_throws(): void
    {
        $body = '{"type":"event_callback"}';
        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withBody($this->factory->createStream($body));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('timestamp');

        $this->verifier->verify($request, $body);
    }

    public function test_timing_safe_comparison(): void
    {
        $timestamp = (string) time();
        $body = '{"type":"event_callback"}';
        $validSig = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'test_signing_secret');

        // Slightly modified signature (one char changed)
        $invalidSig = substr($validSig, 0, -1).((int) substr($validSig, -1) + 1) % 10;

        $request = $this->createRequest($body, $timestamp, $invalidSig);

        $this->expectException(AuthenticationException::class);
        $this->verifier->verify($request, $body);
    }

    private function createRequest(string $body, string $timestamp, string $signature): ServerRequestInterface
    {
        return $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('X-Slack-Request-Timestamp', $timestamp)
            ->withHeader('X-Slack-Signature', $signature)
            ->withBody($this->factory->createStream($body));
    }
}
