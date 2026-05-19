# bootdesk/chat-sdk-adapter-slack

Slack adapter for the laravel-bootdesk multi-platform messaging framework.

## Install

```bash
composer require bootdesk/chat-sdk-adapter-slack
```

Requires a PSR-18 HTTP client (`guzzlehttp/guzzle`, `symfony/http-client`, etc.) and a PSR-17 factory (`nyholm/psr7` bundled).

## Configuration

| Variable | Description | Example |
|----------|-------------|---------|
| `bot_token` | Slack Bot OAuth Token | `xoxb-1234-abcd-...` |
| `http_client` | PSR-18 HTTP client instance | `new GuzzleHttp\Client` |
| `signing_secret` | Slack App Signing Secret | `8f742231b10e...` |

```php
use BootDesk\ChatSDK\Slack\SlackAdapter;

$adapter = new SlackAdapter(
    botToken: env('SLACK_BOT_TOKEN'),
    httpClient: new \GuzzleHttp\Client,
    signingSecret: env('SLACK_SIGNING_SECRET'),
);
```

### Laravel

The `ChatServiceProvider` auto-binds `Psr\Http\Client\ClientInterface` to `GuzzleHttp\Client`. Add to `config/chat.php`:

```php
'slack' => [
    'bot_token'      => env('SLACK_BOT_TOKEN'),
    'signing_secret' => env('SLACK_SIGNING_SECRET'),
],
```

## Quick Example

```php
// Post a message to a Slack channel
$adapter->postMessage('slack:C1234567890', 'Hello from laravel-bootdesk!');

// Reply in a thread
$adapter->postMessage('slack:C1234567890:1234567890.123456', 'Thread reply');
```

## Thread ID Format

| Format | Description |
|--------|-------------|
| `slack:{channelId}` | Top-level channel message |
| `slack:{channelId}:{threadTs}` | Reply within a thread |

## Webhook

Slack sends Event API payloads to your endpoint. Verify requests using the signing secret with the `X-Slack-Signature` header (HMAC-SHA256).

## Feature Matrix

| Feature | Supported |
|---------|-----------|
| Post messages | ✓ |
| Edit messages | ✓ |
| Delete messages | ✓ |
| Reactions | ✓ |
| Typing indicator | ✓ |
| Fetch messages | ✓ |
| Fetch thread info | ✓ |
| Fetch channel info | ✓ |
| Get user | ✓ |
| Open DM | ✗ |
| Stream | ✓ |

## Notes

Supports Socket Mode, interactive messages, slash commands, and app mentions.

## Documentationn
Full API documentation: https://bootdesk.github.io/chat-sdk

## License

MIT
