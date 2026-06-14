<?php

namespace BootDesk\ChatSDK\Slack\Tests;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\HandlesModals;
use BootDesk\ChatSDK\Core\Contracts\HandlesOptionsLoad;
use BootDesk\ChatSDK\Core\Contracts\HandlesReactions;
use BootDesk\ChatSDK\Core\Contracts\HandlesSlackEvents;
use BootDesk\ChatSDK\Core\Contracts\SupportsModals;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\Modals\Modal;
use BootDesk\ChatSDK\Core\Modals\TextInput;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Slack\SlackAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class SlackAdapterTest extends TestCase
{
    private SlackAdapter $adapter;

    private Psr17Factory $factory;

    private array $capturedRequests = [];

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory;
        $this->capturedRequests = [];

        $mockClient = new class($this->capturedRequests) implements ClientInterface
        {
            private array $responses = [];

            public function __construct(private array &$captured)
            {
                $factory = new Psr17Factory;

                $this->responses = [
                    'auth.test' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['ok' => true, 'user_id' => 'UBOT123']))
                    ),
                    'chat.postMessage' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['ok' => true, 'ts' => '1234567890.123456']))
                    ),
                    'chat.update' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['ok' => true, 'ts' => '1234567890.654321']))
                    ),
                    'chat.delete' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['ok' => true]))
                    ),
                    'reactions.add' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['ok' => true]))
                    ),
                    'reactions.remove' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['ok' => true]))
                    ),
                    'conversations.replies' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'ok' => true,
                            'messages' => [
                                ['ts' => '111.222', 'text' => 'Hello', 'user' => 'U123'],
                                ['ts' => '111.333', 'text' => 'World', 'user' => 'U456'],
                            ],
                        ]))
                    ),
                    'conversations.info' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'ok' => true,
                            'channel' => [
                                'id' => 'C123',
                                'name' => 'general',
                                'topic' => ['value' => 'Team chat'],
                                'is_private' => false,
                            ],
                        ]))
                    ),
                    'users.info' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'ok' => true,
                            'user' => [
                                'id' => 'U123',
                                'name' => 'johndoe',
                                'profile' => ['real_name' => 'John Doe', 'email' => 'john@test.com'],
                            ],
                        ]))
                    ),
                    'conversations.open' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'ok' => true,
                            'channel' => ['id' => 'D999'],
                        ]))
                    ),
                ];
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $uri = (string) $request->getUri();
                foreach ($this->responses as $key => $response) {
                    if (str_contains($uri, $key)) {
                        $this->captured[] = $request;

                        return $response;
                    }
                }

                $factory = new Psr17Factory;

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode(['ok' => true]))
                );
            }
        };

        $this->adapter = new SlackAdapter(
            botToken: 'xoxb-test-token',
            signingSecret: 'test_secret',
            httpClient: $mockClient,
            psrFactory: $this->factory,
        );
    }

    public function test_get_name(): void
    {
        $this->assertSame('slack', $this->adapter->getName());
    }

    public function test_thread_id_encoding(): void
    {
        $id = $this->adapter->encodeThreadId(['channel' => 'C123', 'thread_ts' => '1234.5678']);
        $this->assertSame('slack:C123:1234.5678', $id);
    }

    public function test_thread_id_decoding(): void
    {
        $decoded = $this->adapter->decodeThreadId('slack:C123:1234.5678');
        $this->assertSame('C123', $decoded['channel']);
        $this->assertSame('1234.5678', $decoded['thread_ts']);
    }

    public function test_channel_id_from_thread(): void
    {
        $this->assertSame('C123', $this->adapter->channelIdFromThreadId('slack:C123:1234.5678'));
    }

    public function test_url_verification_challenge(): void
    {
        $body = json_encode(['type' => 'url_verification', 'challenge' => 'abc123']);
        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withBody($this->factory->createStream($body));

        $response = $this->adapter->verifyWebhook($request);

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('abc123', $data['challenge']);
    }

    public function test_parse_webhook_message(): void
    {
        $this->adapter->initialize($this->createMock(Chat::class));

        $body = json_encode([
            'event' => [
                'type' => 'message',
                'text' => 'Hello world',
                'user' => 'U123',
                'ts' => '1234.5678',
                'channel' => 'C456',
                'thread_ts' => '1234.5678',
            ],
        ]);

        $timestamp = (string) time();
        $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'test_secret');

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('X-Slack-Request-Timestamp', $timestamp)
            ->withHeader('X-Slack-Signature', $signature)
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('1234.5678', $message->id);
        $this->assertSame('slack:C456:1234.5678', $message->threadId);
        $this->assertSame('U123', $message->author->id);
        $this->assertSame('Hello world', $message->text);
    }

    public function test_parse_dm_message(): void
    {
        $body = json_encode([
            'event' => [
                'type' => 'message',
                'text' => 'Private msg',
                'user' => 'U123',
                'ts' => '1234.5678',
                'channel' => 'D999',
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);
        $this->assertTrue($message->isDM);
    }

    public function test_parse_bot_message(): void
    {
        $body = json_encode([
            'event' => [
                'type' => 'message',
                'text' => 'Bot reply',
                'bot_id' => 'BOT123',
                'ts' => '1234.5678',
                'channel' => 'C456',
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('BOT123', $message->author->id);
        $this->assertTrue($message->author->isBot);
        $this->assertSame('Bot reply', $message->text);
    }

    public function test_parse_self_message(): void
    {
        $this->adapter->initialize($this->createMock(Chat::class));
        $this->assertSame('UBOT123', $this->adapter->getBotUserId());

        $body = json_encode([
            'event' => [
                'type' => 'message',
                'text' => 'Self message',
                'user' => 'UBOT123',
                'ts' => '1234.5678',
                'channel' => 'C456',
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withBody($this->factory->createStream($body));

        // parseWebhook doesn't filter self — Chat.processMessage does
        $message = $this->adapter->parseWebhook($request);
        $this->assertSame('UBOT123', $message->author->id);
    }

    public function test_parse_message_without_text(): void
    {
        $body = json_encode([
            'event' => [
                'type' => 'message',
                'user' => 'U123',
                'ts' => '1234.5678',
                'channel' => 'C456',
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);
        $this->assertSame('', $message->text);
    }

    public function test_parse_message_without_user(): void
    {
        $body = json_encode([
            'event' => [
                'type' => 'message',
                'text' => 'No user',
                'ts' => '1234.5678',
                'channel' => 'C456',
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);
        $this->assertSame('', $message->author->id);
    }

    public function test_parse_message_without_ts(): void
    {
        $body = json_encode([
            'event' => [
                'type' => 'message',
                'text' => 'No timestamp',
                'user' => 'U123',
                'channel' => 'C456',
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);
        $this->assertSame('', $message->id);
    }

    public function test_parse_action_detects_block_actions(): void
    {
        $payload = json_encode([
            'type' => 'block_actions',
            'user' => ['id' => 'U123', 'username' => 'test'],
            'channel' => ['id' => 'C456'],
            'message' => ['ts' => '1234.5678'],
            'actions' => [['action_id' => 'order_confirm', 'value' => '{"item":"Pizza"}']],
            'trigger_id' => 'trigger-999',
        ]);

        $body = http_build_query(['payload' => $payload]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseAction($request);

        $this->assertNotNull($result);
        $this->assertSame('order_confirm', $result['actionId']);
        $this->assertSame('{"item":"Pizza"}', $result['value']);
        $this->assertSame('slack:C456:1234.5678', $result['threadId']);
        $this->assertSame('1234.5678', $result['messageId']);
        $this->assertSame('U123', $result['userId']);
        $this->assertSame('trigger-999', $result['triggerId']);
    }

    public function test_parse_action_returns_null_for_json_payload(): void
    {
        $body = json_encode(['type' => 'event_callback']);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseAction($request));
    }

    public function test_parse_action_returns_null_for_slash_command(): void
    {
        $body = http_build_query(['command' => '/help', 'text' => '']);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseAction($request));
    }

    public function test_parse_action_in_thread_uses_container_thread_ts(): void
    {
        $payload = json_encode([
            'type' => 'block_actions',
            'user' => ['id' => 'U1'],
            'channel' => ['id' => 'C456'],
            'container' => [
                'type' => 'message',
                'message_ts' => '1111.2222',
                'channel_id' => 'C456',
                'thread_ts' => '9999.8888',
            ],
            'message' => ['ts' => '1111.2222'],
            'actions' => [['action_id' => 'btn']],
        ]);

        $body = http_build_query(['payload' => $payload]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseAction($request);

        $this->assertNotNull($result);
        $this->assertSame('slack:C456:9999.8888', $result['threadId']);
    }

    public function test_parse_slash_command_detects_form_urlencoded(): void
    {
        $body = http_build_query([
            'command' => '/help',
            'text' => 'topic search',
            'user_id' => 'U123',
            'channel_id' => 'C456',
            'trigger_id' => 'trigger-789',
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseSlashCommand($request);

        $this->assertNotNull($result);
        $this->assertSame('/help', $result['command']);
        $this->assertSame('topic search', $result['text']);
        $this->assertSame('U123', $result['userId']);
        $this->assertSame('slack:C456', $result['channelId']);
        $this->assertSame('trigger-789', $result['triggerId']);
        $this->assertFalse($result['isBot']);
    }

    public function test_parse_slash_command_returns_null_for_json_payload(): void
    {
        $body = json_encode(['event' => ['text' => 'hello']]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseSlashCommand($request));
    }

    public function test_parse_slash_command_returns_null_for_interactive_payload(): void
    {
        $body = http_build_query([
            'payload' => json_encode(['type' => 'block_actions']),
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseSlashCommand($request));
    }

    public function test_parse_slash_command_returns_null_without_command(): void
    {
        $body = http_build_query([
            'text' => 'hello',
            'user_id' => 'U123',
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseSlashCommand($request));
    }

    public function test_parse_slash_command_without_trigger_id(): void
    {
        $body = http_build_query([
            'command' => '/status',
            'text' => '',
            'user_id' => 'U456',
            'channel_id' => 'C789',
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseSlashCommand($request);

        $this->assertNotNull($result);
        $this->assertSame('/status', $result['command']);
        $this->assertSame('', $result['text']);
        $this->assertNull($result['triggerId']);
    }

    public function test_post_message(): void
    {
        $sent = $this->adapter->postMessage(
            'slack:C123:1234.5678',
            PostableMessage::text('Hello Slack')
        );

        $this->assertSame('1234567890.123456', $sent->id);
        $this->assertSame('slack:C123:1234.5678', $sent->threadId);
    }

    public function test_edit_message(): void
    {
        $sent = $this->adapter->editMessage(
            'slack:C123:1234.5678',
            '1234.9999',
            PostableMessage::text('Updated')
        );

        $this->assertSame('1234567890.654321', $sent->id);
    }

    public function test_delete_message(): void
    {
        $this->adapter->deleteMessage('slack:C123:1234.5678', '1234.9999');
        $this->assertTrue(true); // No exception
    }

    public function test_add_reaction(): void
    {
        $this->adapter->addReaction('slack:C123:1234.5678', '1234.9999', 'thumbsup');
        $this->assertTrue(true);
    }

    public function test_remove_reaction(): void
    {
        $this->adapter->removeReaction('slack:C123:1234.5678', '1234.9999', 'thumbsup');
        $this->assertTrue(true);
    }

    public function test_fetch_messages(): void
    {
        $result = $this->adapter->fetchMessages('slack:C123:1234.5678');

        $this->assertCount(2, $result->messages);
        $this->assertSame('111.222', $result->messages[0]->id);
    }

    public function test_fetch_thread(): void
    {
        $info = $this->adapter->fetchThread('slack:C123:1234.5678');

        $this->assertSame('slack:C123:1234.5678', $info->id);
        $this->assertSame('C123', $info->channelId);
    }

    public function test_fetch_channel_info(): void
    {
        $info = $this->adapter->fetchChannelInfo('C123');

        $this->assertSame('C123', $info->id);
        $this->assertSame('general', $info->name);
        $this->assertSame('Team chat', $info->topic);
        $this->assertFalse($info->isPrivate);
    }

    public function test_get_user(): void
    {
        $user = $this->adapter->getUser('U123');

        $this->assertSame('U123', $user->id);
        $this->assertSame('johndoe', $user->name);
        $this->assertSame('john@test.com', $user->email);
    }

    public function test_open_dm(): void
    {
        $dmChannelId = $this->adapter->openDM('U123');
        $this->assertSame('D999', $dmChannelId);
    }

    public function test_get_format_converter(): void
    {
        $this->assertNotNull($this->adapter->getFormatConverter());
    }

    public function test_initialize_resolves_bot_user_id(): void
    {
        $chat = $this->createMock(Chat::class);
        $this->adapter->initialize($chat);

        $this->assertSame('UBOT123', $this->adapter->getBotUserId());
    }

    public function test_post_message_with_card(): void
    {
        $card = Card::make()
            ->header('Deploy Ready')
            ->section(fn ($s) => $s->text('Build passed'))
            ->actions([Button::primary('Deploy', 'deploy')]);

        $sent = $this->adapter->postMessage(
            'slack:C123:1234.5678',
            PostableMessage::card($card)
        );

        $this->assertSame('1234567890.123456', $sent->id);
    }

    public function test_stream_collects_and_posts(): void
    {
        $sent = $this->adapter->stream(
            'slack:C123:1234.5678',
            ['Hello ', 'world', '!'],
        );

        $this->assertNotNull($sent);
        $this->assertSame('1234567890.123456', $sent->id);
    }

    public function test_start_typing_is_noop(): void
    {
        $this->adapter->startTyping('slack:C123:1234.5678');
        $this->assertTrue(true);
    }

    public function test_disconnect_is_noop(): void
    {
        $this->adapter->disconnect();
        $this->assertTrue(true);
    }

    public function test_api_call_throws_authentication_exception_on_auth_error(): void
    {
        $factory = new Psr17Factory;
        $mockClient = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $f = new Psr17Factory;

                return $f->createResponse(200)->withBody(
                    $f->createStream(json_encode(['ok' => false, 'error' => 'invalid_auth']))
                );
            }
        };

        $adapter = new SlackAdapter(
            botToken: 'xoxb-bad-token',
            httpClient: $mockClient,
            psrFactory: $factory,
        );
        $adapter->initialize($this->createMock(Chat::class));

        $this->expectException(AuthenticationException::class);
        $adapter->postMessage('slack:C123', PostableMessage::text('test'));
    }

    public function test_implements_handles_reactions(): void
    {
        $this->assertInstanceOf(HandlesReactions::class, $this->adapter);
    }

    public function test_implements_handles_modals(): void
    {
        $this->assertInstanceOf(HandlesModals::class, $this->adapter);
    }

    public function test_implements_handles_options_load(): void
    {
        $this->assertInstanceOf(HandlesOptionsLoad::class, $this->adapter);
    }

    public function test_implements_handles_slack_events(): void
    {
        $this->assertInstanceOf(HandlesSlackEvents::class, $this->adapter);
    }

    public function test_implements_supports_modals(): void
    {
        $this->assertInstanceOf(SupportsModals::class, $this->adapter);
    }

    public function test_parse_reaction_added(): void
    {
        $body = json_encode([
            'event' => [
                'type' => 'reaction_added',
                'user' => 'U123',
                'reaction' => '+1',
                'item' => ['type' => 'message', 'channel' => 'C123', 'ts' => '111.222'],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseReaction($request);

        $this->assertNotNull($result);
        $this->assertSame('thumbs_up', $result['emoji']);
        $this->assertSame('+1', $result['rawEmoji']);
        $this->assertTrue($result['added']);
    }

    public function test_parse_reaction_removed(): void
    {
        $body = json_encode([
            'event' => [
                'type' => 'reaction_removed',
                'user' => 'U123',
                'reaction' => 'thumbsup',
                'item' => ['type' => 'message', 'channel' => 'C123', 'ts' => '111.222'],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseReaction($request);

        $this->assertNotNull($result);
        $this->assertFalse($result['added']);
    }

    public function test_parse_reaction_skips_non_message_item(): void
    {
        $body = json_encode([
            'event' => [
                'type' => 'reaction_added',
                'user' => 'U123',
                'reaction' => '+1',
                'item' => ['type' => 'file', 'channel' => 'C123', 'ts' => '111.222'],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseReaction($request));
    }

    public function test_parse_modal_submit(): void
    {
        $body = http_build_query(['payload' => json_encode([
            'type' => 'view_submission',
            'user' => ['id' => 'U123'],
            'view' => [
                'id' => 'V456',
                'callback_id' => 'feedback',
                'private_metadata' => 'ctx_999',
                'state' => ['values' => [
                    'comment_block' => ['comment' => ['value' => 'Great job!']],
                ]],
            ],
        ])]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseModalSubmit($request);

        $this->assertNotNull($result);
        $this->assertSame('feedback', $result['callbackId']);
        $this->assertSame('V456', $result['viewId']);
        $this->assertSame('ctx_999', $result['contextId']);
        $this->assertSame('Great job!', $result['values']['comment']);
    }

    public function test_parse_modal_close(): void
    {
        $body = http_build_query(['payload' => json_encode([
            'type' => 'view_closed',
            'user' => ['id' => 'U123'],
            'view' => [
                'id' => 'V456',
                'callback_id' => 'feedback',
                'private_metadata' => 'ctx_999',
            ],
        ])]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseModalClose($request);

        $this->assertNotNull($result);
        $this->assertSame('feedback', $result['callbackId']);
        $this->assertSame('ctx_999', $result['contextId']);
    }

    public function test_parse_options_load(): void
    {
        $body = http_build_query(['payload' => json_encode([
            'type' => 'block_suggestion',
            'action_id' => 'category',
            'value' => 'bug',
            'user' => ['id' => 'U123'],
        ])]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseOptionsLoad($request);

        $this->assertNotNull($result);
        $this->assertSame('category', $result['actionId']);
        $this->assertSame('bug', $result['query']);
    }

    public function test_respond_to_options_load_returns_json(): void
    {
        $response = $this->adapter->respondToOptionsLoad([
            ['text' => 'Bug Report', 'value' => 'bug'],
        ]);

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('options', $body);
        $this->assertSame('Bug Report', $body['options'][0]['text']['text']);
    }

    public function test_respond_to_options_load_null(): void
    {
        $this->assertNull($this->adapter->respondToOptionsLoad(null));
    }

    public function test_parse_assistant_thread_started(): void
    {
        $body = json_encode([
            'event' => [
                'type' => 'assistant_thread_started',
                'assistant_thread' => [
                    'channel_id' => 'C123',
                    'thread_ts' => '111.222',
                    'user_id' => 'U456',
                    'context' => ['channel_id' => 'C123', 'team_id' => 'T1'],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseAssistantThreadStarted($request);

        $this->assertNotNull($result);
        $this->assertSame('C123', $result['channelId']);
        $this->assertSame('U456', $result['userId']);
        $this->assertNotNull($result['context']);
    }

    public function test_parse_app_home_opened(): void
    {
        $body = json_encode([
            'event' => [
                'type' => 'app_home_opened',
                'channel' => 'D123',
                'user' => 'U456',
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseAppHomeOpened($request);

        $this->assertNotNull($result);
        $this->assertSame('D123', $result['channelId']);
        $this->assertSame('U456', $result['userId']);
    }

    public function test_parse_member_joined_channel(): void
    {
        $body = json_encode([
            'event' => [
                'type' => 'member_joined_channel',
                'channel' => 'C123',
                'user' => 'U456',
                'inviter' => 'U789',
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseMemberJoinedChannel($request);

        $this->assertNotNull($result);
        $this->assertSame('C123', $result['channelId']);
        $this->assertSame('U456', $result['userId']);
        $this->assertSame('U789', $result['inviterId']);
    }

    public function test_open_modal_returns_view_id(): void
    {
        $modal = new Modal(callbackId: 'test', title: 'Test', children: [
            new TextInput(id: 'name', label: 'Name'),
        ]);

        $result = $this->adapter->openModal('trigger123', $modal, 'ctx_999');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('viewId', $result);
    }

    // --- Fixture-based tests from slack.json ---

    public function test_fixture_bot_mention(): void
    {
        $this->adapter->initialize($this->createMock(Chat::class));

        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/slack.json'),
            true
        );

        $timestamp = (string) time();
        $body = json_encode($fixture['mention']);
        $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'test_secret');

        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('X-Slack-Request-Timestamp', $timestamp)
            ->withHeader('X-Slack-Signature', $signature)
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('1767224888.280449', $message->id);
        $this->assertStringContainsString('Hey', $message->text);
        $this->assertSame('U00FAKEUSER1', $message->author->id);
        $this->assertSame('slack:C00FAKECHAN1:1767224888.280449', $message->threadId);
        $this->assertFalse($message->isDM);
    }

    public function test_fixture_follow_up_in_thread(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/slack.json'),
            true
        );

        $body = json_encode($fixture['followUp']);
        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('1767224901.701849', $message->id);
        $this->assertSame('Hi', $message->text);
        $this->assertSame('slack:C00FAKECHAN1:1767224888.280449', $message->threadId);
    }

    // --- Fixture-based tests from slack-slash-commands.json ---

    public function test_fixture_slash_command_basic(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/slack-slash-commands.json'),
            true
        );

        $payload = $fixture['slashCommand'];
        $body = http_build_query($payload);
        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseSlashCommand($request);

        $this->assertNotNull($result);
        $this->assertSame('/test-feedback', $result['command']);
        $this->assertSame('', $result['text']);
        $this->assertSame('U00FAKEUSER2', $result['userId']);
        $this->assertSame('10520020890661.10229338706656.2e2188a074adf3bf9f8456b30180f405', $result['triggerId']);
    }

    // --- Fixture-based tests from slack-actions-reactions.json ---

    public function test_fixture_block_action(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/slack-actions-reactions.json'),
            true
        );

        $payload = json_encode($fixture['action']);
        $body = http_build_query(['payload' => $payload]);
        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseAction($request);

        $this->assertNotNull($result);
        $this->assertSame('info', $result['actionId']);
        $this->assertSame('U00FAKEUSER1', $result['userId']);
        $this->assertSame('10215325802133.3901254001572.2e2548aa918b35fd85829545c2d4ae2b', $result['triggerId']);
        $this->assertSame('slack:C00FAKECHAN1:1767326125.870439', $result['threadId']);
    }

    public function test_fixture_reaction_added(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/slack-actions-reactions.json'),
            true
        );

        $body = json_encode($fixture['reaction']);
        $request = $this->factory->createServerRequest('POST', '/webhooks/slack')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseReaction($request);

        $this->assertNotNull($result);
        $this->assertSame('thumbs_up', $result['emoji']);
        $this->assertSame('+1', $result['rawEmoji']);
        $this->assertTrue($result['added']);
        $this->assertSame('U00FAKEUSER1', $result['userId']);
        $this->assertSame('slack:C00FAKECHAN1:1767326126.896109', $result['threadId']);
    }
}
