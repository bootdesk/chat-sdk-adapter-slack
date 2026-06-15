<?php

namespace BootDesk\ChatSDK\Slack;

use BootDesk\ChatSDK\Core\Attachment;
use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\CompositeInterfaces\HandlesInteractions;
use BootDesk\ChatSDK\Core\Contracts\CompositeInterfaces\SupportsMessageMutability;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\Contracts\HandlesModals;
use BootDesk\ChatSDK\Core\Contracts\HandlesOptionsLoad;
use BootDesk\ChatSDK\Core\Contracts\HandlesSlackEvents;
use BootDesk\ChatSDK\Core\Contracts\HasAuthorInfo;
use BootDesk\ChatSDK\Core\Contracts\MustRehydrateAttachments;
use BootDesk\ChatSDK\Core\Contracts\RequiresAsyncResponse;
use BootDesk\ChatSDK\Core\Contracts\SupportsModals;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\FileUpload;
use BootDesk\ChatSDK\Core\LocalizationType;
use BootDesk\ChatSDK\Core\LocalizationValue;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\Modals\Modal;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\Support\EmojiResolver;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

class SlackAdapter implements Adapter, HandlesInteractions, HandlesModals, HandlesOptionsLoad, HandlesSlackEvents, HasAuthorInfo, MustRehydrateAttachments, RequiresAsyncResponse, SupportsMessageMutability, SupportsModals
{
    protected ?string $botUserId = null;

    protected SlackFormatConverter $formatConverter;

    protected ?SlackWebhookVerifier $webhookVerifier = null;

    protected EmojiResolver $emojiResolver;

    public function __construct(
        protected readonly string $botToken,
        protected readonly ClientInterface $httpClient,
        ?string $signingSecret = null,
        protected readonly string $apiUrl = 'https://slack.com/api/',
        protected readonly ?Psr17Factory $psrFactory = null,
        ?EmojiResolver $emojiResolver = null,
    ) {
        $this->formatConverter = new SlackFormatConverter;
        $this->emojiResolver = $emojiResolver ?? EmojiResolver::default();

        if ($signingSecret !== null) {
            $this->webhookVerifier = new SlackWebhookVerifier($signingSecret);
        }
    }

    public function getName(): string
    {
        return 'slack';
    }

    public function getBotUserId(): ?string
    {
        return $this->botUserId;
    }

    public function verifyWebhook(ServerRequestInterface $request): ?ResponseInterface
    {
        $body = (string) $request->getBody();

        // URL verification challenge
        $payload = json_decode($body, true);
        if (is_array($payload) && ($payload['type'] ?? null) === 'url_verification') {
            $factory = $this->psrFactory ?? new Psr17Factory;

            return $factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode([
                    'challenge' => $payload['challenge'],
                ])));
        }

        // Verify signature if signing secret is configured
        if ($this->webhookVerifier instanceof SlackWebhookVerifier) {
            $this->webhookVerifier->verify($request, $body);
        }

        return null;
    }

    public function parseAction(ServerRequestInterface $request): ?array
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if (! str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return null;
        }

        $body = (string) $request->getBody();
        parse_str($body, $params);

        if (! isset($params['payload'])) {
            return null;
        }

        $payload = json_decode($params['payload'], true);
        if (! is_array($payload) || ($payload['type'] ?? '') !== 'block_actions') {
            return null;
        }

        $action = $payload['actions'][0] ?? [];
        if (! isset($action['action_id'])) {
            return null;
        }

        $channelId = $payload['channel']['id'] ?? '';
        $threadTs = $payload['container']['thread_ts'] ?? $payload['message']['ts'] ?? '';
        $threadId = $threadTs !== ''
            ? $this->encodeThreadId(['channel' => $channelId, 'thread_ts' => $threadTs])
            : "slack:{$channelId}";
        $messageTs = $payload['message']['ts'] ?? '';
        $user = $payload['user'] ?? [];

        return [
            'author' => new Author(
                id: $user['id'] ?? '',
                name: $user['name'] ?? ($user['username'] ?? null),
            ),
            'actionId' => $action['action_id'],
            'value' => $action['value'] ?? null,
            'threadId' => $threadId,
            'messageId' => $messageTs,
            'userId' => $user['id'] ?? '',
            'isBot' => false,
            'isMe' => false,
            'triggerId' => $payload['trigger_id'] ?? null,
            'raw' => $params['payload'],
            'callbackQueryId' => null,
            'originId' => null,
        ];
    }

    public function acknowledgeAction(?string $callbackQueryId): ?ResponseInterface
    {
        return null;
    }

    public function parseReaction(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return null;
        }

        $event = $payload['event'] ?? [];
        $type = $event['type'] ?? '';

        if ($type !== 'reaction_added' && $type !== 'reaction_removed') {
            return null;
        }

        if (($event['item']['type'] ?? '') !== 'message') {
            return null;
        }

        // Self-filter: skip reactions from the bot itself
        if ($this->botUserId !== null && ($event['user'] ?? '') === $this->botUserId) {
            return null;
        }

        // Resolve parent thread ts — when reaction is on a reply,
        // event.item.ts is the reply's ts, not the parent thread_ts.
        $parentTs = $event['item']['ts'];
        try {
            $response = $this->apiCall('conversations.replies', [
                'channel' => $event['item']['channel'],
                'ts' => $event['item']['ts'],
                'limit' => 1,
            ]);
            $firstMessage = $response['messages'][0] ?? [];
            if (isset($firstMessage['thread_ts'])) {
                $parentTs = $firstMessage['thread_ts'];
            }
        } catch (AdapterException) {
            // Use item ts as fallback
        }

        $threadId = $this->encodeThreadId([
            'channel' => $event['item']['channel'],
            'thread_ts' => $parentTs,
        ]);

        return [
            'author' => new Author(id: $event['user']),
            'emoji' => $this->emojiResolver->fromSlack($event['reaction']),
            'rawEmoji' => $event['reaction'],
            'added' => $type === 'reaction_added',
            'threadId' => $threadId,
            'messageId' => $event['item']['ts'],
            'userId' => $event['user'],
            'raw' => $payload,
            'originId' => null,
        ];
    }

    public function parseModalSubmit(ServerRequestInterface $request): ?array
    {
        $payload = $this->parseInteractivePayload($request);
        if ($payload === null || ($payload['type'] ?? '') !== 'view_submission') {
            return null;
        }

        $view = $payload['view'] ?? [];
        $user = $payload['user'] ?? [];

        // Flatten values from view.state.values
        $values = [];
        foreach ($view['state']['values'] ?? [] as $block) {
            foreach ($block as $actionId => $field) {
                $values[$actionId] = $field['value'] ?? $field['selected_option']['value'] ?? $field['selected_date'] ?? $field['selected_time'] ?? current((array) ($field['selected_users'] ?? [])) ?: current((array) ($field['selected_channels'] ?? [])) ?: '';
            }
        }

        return [
            'author' => new Author(
                id: $user['id'] ?? '',
                name: $user['name'] ?? ($user['username'] ?? null),
            ),
            'callbackId' => $view['callback_id'] ?? '',
            'viewId' => $view['id'] ?? '',
            'values' => $values,
            'userId' => $user['id'] ?? '',
            'contextId' => $view['private_metadata'] ?? null,
            'raw' => $payload,
        ];
    }

    public function parseModalClose(ServerRequestInterface $request): ?array
    {
        $payload = $this->parseInteractivePayload($request);
        if ($payload === null || ($payload['type'] ?? '') !== 'view_closed') {
            return null;
        }

        $view = $payload['view'] ?? [];
        $user = $payload['user'] ?? [];

        return [
            'author' => new Author(
                id: $user['id'] ?? '',
                name: $user['name'] ?? ($user['username'] ?? null),
            ),
            'callbackId' => $view['callback_id'] ?? '',
            'viewId' => $view['id'] ?? '',
            'userId' => $user['id'] ?? '',
            'contextId' => $view['private_metadata'] ?? null,
            'raw' => $payload,
        ];
    }

    public function parseOptionsLoad(ServerRequestInterface $request): ?array
    {
        $payload = $this->parseInteractivePayload($request);
        if ($payload === null || ($payload['type'] ?? '') !== 'block_suggestion') {
            return null;
        }

        $user = $payload['user'] ?? [];

        return [
            'author' => new Author(
                id: $user['id'] ?? '',
                name: $user['name'] ?? ($user['username'] ?? null),
            ),
            'actionId' => $payload['action_id'] ?? '',
            'query' => $payload['value'] ?? '',
            'userId' => $user['id'] ?? '',
            'raw' => $payload,
        ];
    }

    public function respondToOptionsLoad(?array $options): ?ResponseInterface
    {
        if ($options === null) {
            return null;
        }

        $slackOptions = array_map(function (array $option): array {
            return [
                'text' => ['type' => 'plain_text', 'text' => $option['text'] ?? ''],
                'value' => $option['value'] ?? '',
            ];
        }, $options);

        $factory = $this->psrFactory ?? new Psr17Factory;
        $response = $factory->createResponse(200)
            ->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode(['options' => $slackOptions]));

        return $response;
    }

    public function parseAssistantThreadStarted(ServerRequestInterface $request): ?array
    {
        return $this->parseSlackEvent($request, 'assistant_thread_started', function (array $event): array {
            $thread = $event['assistant_thread'] ?? [];

            return [
                'channelId' => $thread['channel_id'] ?? '',
                'threadId' => $this->encodeThreadId(['channel' => $thread['channel_id'] ?? '', 'thread_ts' => $thread['thread_ts'] ?? '']),
                'threadTs' => $thread['thread_ts'] ?? '',
                'userId' => $thread['user_id'] ?? '',
                'context' => $thread['context'] ?? null,
                'raw' => $event,
            ];
        });
    }

    public function parseAssistantContextChanged(ServerRequestInterface $request): ?array
    {
        return $this->parseSlackEvent($request, 'assistant_thread_context_changed', function (array $event): array {
            $thread = $event['assistant_thread'] ?? [];

            return [
                'channelId' => $thread['channel_id'] ?? '',
                'threadId' => $this->encodeThreadId(['channel' => $thread['channel_id'] ?? '', 'thread_ts' => $thread['thread_ts'] ?? '']),
                'threadTs' => $thread['thread_ts'] ?? '',
                'userId' => $thread['user_id'] ?? '',
                'context' => $thread['context'] ?? null,
                'raw' => $event,
            ];
        });
    }

    public function parseAppHomeOpened(ServerRequestInterface $request): ?array
    {
        return $this->parseSlackEvent($request, 'app_home_opened', function (array $event): array {
            return [
                'channelId' => $event['channel'] ?? '',
                'userId' => $event['user'] ?? '',
                'raw' => $event,
            ];
        });
    }

    public function parseMemberJoinedChannel(ServerRequestInterface $request): ?array
    {
        return $this->parseSlackEvent($request, 'member_joined_channel', function (array $event): array {
            return [
                'channelId' => $event['channel'] ?? '',
                'userId' => $event['user'] ?? '',
                'inviterId' => $event['inviter'] ?? null,
                'raw' => $event,
            ];
        });
    }

    public function parseSlashCommand(ServerRequestInterface $request): ?array
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if (! str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return null;
        }

        $body = (string) $request->getBody();
        parse_str($body, $params);

        if (! isset($params['command']) || isset($params['payload'])) {
            return null;
        }

        $channelId = isset($params['channel_id']) ? "slack:{$params['channel_id']}" : '';

        return [
            'author' => new Author(
                id: $params['user_id'] ?? '',
                name: $params['user_name'] ?? null,
            ),
            'command' => $params['command'],
            'text' => $params['text'] ?? '',
            'userId' => $params['user_id'] ?? '',
            'isBot' => false,
            'isMe' => false,
            'channelId' => $channelId,
            'triggerId' => $params['trigger_id'] ?? null,
            'raw' => $body,
        ];
    }

    public function parseWebhook(ServerRequestInterface $request): Message
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if ($payload === null) {
            throw new AdapterException('Invalid JSON payload from Slack');
        }

        $event = $payload['event'] ?? $payload;

        $text = $event['text'] ?? '';
        $userId = $event['user'] ?? ($event['bot_id'] ?? '');
        $messageTs = $event['ts'] ?? '';
        $channelId = $event['channel'] ?? '';
        $threadTs = $event['thread_ts'] ?? $messageTs;

        $isMention = isset($event['text']) && str_contains($event['text'], "<@{$this->botUserId}>");
        $isDM = str_starts_with($channelId, 'D');

        $threadId = $this->encodeThreadId([
            'channel' => $channelId,
            'thread_ts' => $threadTs,
        ]);

        $isMe = $this->botUserId !== null && ($userId === $this->botUserId || $userId === '');

        return new Message(
            id: $messageTs,
            threadId: $threadId,
            author: new Author(
                id: $userId,
                isMe: $isMe,
                isBot: isset($event['bot_id']),
            ),
            text: $text,
            formatted: $this->formatConverter->toAst($text),
            attachments: $this->extractAttachments($event),
            isMention: $isMention,
            isDM: $isDM,
            raw: $body,
        );
    }

    /** @return Attachment[] */
    protected function extractAttachments(array $event): array
    {
        $attachments = [];

        foreach ($event['files'] ?? [] as $file) {
            $mimeType = $file['mimetype'] ?? '';
            $type = match (true) {
                str_starts_with($mimeType, 'image/') => 'image',
                str_starts_with($mimeType, 'video/') => 'video',
                str_starts_with($mimeType, 'audio/') => 'audio',
                default => 'file',
            };

            $attachments[] = new Attachment(
                type: $type,
                url: $file['url_private'] ?? null,
                name: $file['name'] ?? null,
                mimeType: $mimeType ?: null,
                size: $file['size'] ?? null,
                width: $file['original_w'] ?? null,
                height: $file['original_h'] ?? null,
                fetchData: [$this, 'fetchMedia'],
            );
        }

        return $attachments;
    }

    public function encodeThreadId(mixed $platformData): string
    {
        $channel = $platformData['channel'] ?? '';
        $threadTs = $platformData['thread_ts'] ?? '';

        return "slack:{$channel}:{$threadTs}";
    }

    public function decodeThreadId(string $threadId): mixed
    {
        $parts = explode(':', $threadId, 3);

        return [
            'channel' => $parts[1] ?? '',
            'thread_ts' => $parts[2] ?? '',
        ];
    }

    public function channelIdFromThreadId(string $threadId): string
    {
        return $this->decodeThreadId($threadId)['channel'];
    }

    public function postMessage(string $threadId, PostableMessage $message): SentMessage
    {
        $decoded = $this->decodeThreadId($threadId);

        // Files (binary upload) — getUploadURLExternal → upload → completeUploadExternal
        if ($message->files !== []) {
            $text = $message->getTextContent();
            $threadTs = ($decoded['thread_ts'] !== '' && $decoded['thread_ts'] !== null) ? $decoded['thread_ts'] : null;
            $fileIds = [];
            foreach ($message->files as $file) {
                $fileId = $this->uploadFile($decoded['channel'], $file, $text, $threadTs);
                if ($fileId !== null) {
                    $fileIds[] = $fileId;
                }
            }

            if ($fileIds === []) {
                // All uploads failed, fall back to text-only message
                $params = $this->buildMessageParams($message);
                $params['channel'] = $decoded['channel'];
                if ($threadTs !== null) {
                    $params['thread_ts'] = $threadTs;
                }
                $response = $this->apiCall('chat.postMessage', $params);

                return new SentMessage(
                    id: $response['ts'],
                    threadId: $threadId,
                    timestamp: $response['ts'],
                );
            }

            return new SentMessage(
                id: 'file-'.implode('-', $fileIds),
                threadId: $threadId,
                timestamp: (string) time(),
            );
        }

        $params = $this->buildMessageParams($message);

        // URL-based attachments — add image blocks / text links
        if ($message->attachments !== []) {
            $blocks = $params['blocks'] ?? [];

            // Convert text to a section block so it renders inline, not just in notifications
            $textContent = '';
            $useMrkdwn = false;
            if (isset($params['markdown_text'])) {
                $textContent = $params['markdown_text'];
                $useMrkdwn = true;
                unset($params['markdown_text']);
            } elseif (isset($params['text'])) {
                $textContent = $params['text'];
            }
            if ($textContent !== '') {
                $blocks = array_merge([['type' => 'section', 'text' => [
                    'type' => 'mrkdwn',
                    'text' => $useMrkdwn ? $this->formatConverter->toMrkdwn($textContent) : $textContent,
                ]]], $blocks);
            }

            foreach ($message->attachments as $att) {
                if ($att->type === 'image' && $att->url !== null) {
                    $blocks[] = [
                        'type' => 'image',
                        'image_url' => $att->url,
                        'alt_text' => $att->name ?? 'Image',
                    ];
                } elseif ($att->url !== null) {
                    $blocks[] = [
                        'type' => 'section',
                        'text' => ['type' => 'mrkdwn', 'text' => "<{$att->url}|".($att->name ?? 'Attachment').'>'],
                    ];
                }
            }
            $params['blocks'] = $blocks;
            // Keep text as plain fallback for notifications
            $params['text'] = $message->getTextContent();
        }

        $params['channel'] = $decoded['channel'];

        if ($decoded['thread_ts'] !== '' && $decoded['thread_ts'] !== null) {
            $params['thread_ts'] = $decoded['thread_ts'];
        }

        $response = $this->apiCall('chat.postMessage', $params);

        return new SentMessage(
            id: $response['ts'],
            threadId: $threadId,
            timestamp: $response['ts'],
        );
    }

    protected function uploadFile(string $channel, FileUpload $file, string $initialComment = '', ?string $threadTs = null): ?string
    {
        $factory = $this->psrFactory ?? new Psr17Factory;

        // Step 1: Get upload URL (Slack requires form-encoded for file endpoints)
        $uploadData = $this->apiCall('files.getUploadURLExternal', [
            'filename' => $file->filename,
            'length' => $file->getSize(),
            'alt_text' => $file->filename,
        ], 'application/x-www-form-urlencoded');

        $uploadUrl = $uploadData['upload_url'] ?? null;
        $fileId = $uploadData['file_id'] ?? null;

        if ($uploadUrl === null || $fileId === null) {
            return null;
        }

        $request = $factory->createRequest('POST', $uploadUrl)
            ->withHeader('Content-Type', $file->mimeType ?? 'application/octet-stream')
            ->withBody(is_resource($file->data)
                ? $factory->createStreamFromResource($file->data)
                : $factory->createStream($file->data));

        $psrResponse = $this->httpClient->sendRequest($request);
        $statusCode = $psrResponse->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            return null;
        }

        $completeParams = [
            'files' => json_encode([['id' => $fileId, 'title' => $file->filename]]),
            'channel_id' => $channel,
        ];
        if ($initialComment !== '') {
            $completeParams['initial_comment'] = $this->formatConverter->toMrkdwn($initialComment);
        }
        if ($threadTs !== null) {
            $completeParams['thread_ts'] = $threadTs;
        }

        $this->apiCall('files.completeUploadExternal', $completeParams, 'application/x-www-form-urlencoded');

        return $fileId;
    }

    public function editMessage(string $threadId, string $messageId, PostableMessage $message): SentMessage
    {
        $decoded = $this->decodeThreadId($threadId);
        $params = $this->buildMessageParams($message);
        $params['channel'] = $decoded['channel'];
        $params['ts'] = $messageId;

        $response = $this->apiCall('chat.update', $params);

        return new SentMessage(
            id: $response['ts'],
            threadId: $threadId,
            timestamp: $response['ts'],
        );
    }

    public function deleteMessage(string $threadId, string $messageId): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $this->apiCall('chat.delete', [
            'channel' => $decoded['channel'],
            'ts' => $messageId,
        ]);
    }

    public function addReaction(string $threadId, string $messageId, string $emoji): void
    {
        $this->apiCall('reactions.add', [
            'channel' => $this->channelIdFromThreadId($threadId),
            'timestamp' => $messageId,
            'name' => $this->emojiResolver->toSlack($emoji),
        ]);
    }

    public function removeReaction(string $threadId, string $messageId, string $emoji): void
    {
        $this->apiCall('reactions.remove', [
            'channel' => $this->channelIdFromThreadId($threadId),
            'timestamp' => $messageId,
            'name' => $this->emojiResolver->toSlack($emoji),
        ]);
    }

    public function startTyping(string $threadId, ?string $status = null): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $threadTs = $decoded['thread_ts'] ?? '';

        if ($threadTs === '') {
            return;
        }

        try {
            $this->apiCall('assistant.threads.setStatus', [
                'channel_id' => $decoded['channel'],
                'thread_ts' => $threadTs,
                'status' => $status ?? 'Typing...',
                'loading_messages' => [$status ?? 'Typing...'],
            ]);
        } catch (AdapterException) {
        }
    }

    public function fetchMessages(string $threadId, ?FetchOptions $options = null): FetchResult
    {
        $decoded = $this->decodeThreadId($threadId);
        $params = [
            'channel' => $decoded['channel'],
            'limit' => $options->limit ?? 50,
        ];

        if ($decoded['thread_ts'] !== '') {
            $params['ts'] = $decoded['thread_ts'];
        }

        $response = $this->apiCall('conversations.replies', $params);

        $messages = [];
        foreach ($response['messages'] ?? [] as $msg) {
            $messages[] = new Message(
                id: $msg['ts'],
                threadId: $threadId,
                author: new Author(id: $msg['user'] ?? ($msg['bot_id'] ?? '')),
                text: $msg['text'] ?? '',
            );
        }

        return new FetchResult(
            messages: $messages,
            nextCursor: $response['response_metadata']['next_cursor'] ?? null,
        );
    }

    public function fetchThread(string $threadId): ThreadInfo
    {
        $decoded = $this->decodeThreadId($threadId);

        try {
            $response = $this->apiCall('conversations.info', [
                'channel' => $decoded['channel'],
            ]);
        } catch (AdapterException) {
            return new ThreadInfo(
                id: $threadId,
                channelId: $decoded['channel'],
            );
        }

        $channel = $response['channel'] ?? [];

        return new ThreadInfo(
            id: $threadId,
            channelId: $decoded['channel'],
            title: $channel['name'] ?? null,
            messageCount: $channel['num_members'] ?? null,
            topic: $channel['topic']['value'] ?? ($channel['purpose']['value'] ?? null),
            isArchived: $channel['is_archived'] ?? null,
        );
    }

    public function editThread(string $threadId, ThreadInfo $threadInfo): ThreadInfo
    {
        $decoded = $this->decodeThreadId($threadId);
        $channel = $decoded['channel'];

        if ($threadInfo->title !== null) {
            $this->apiCall('conversations.rename', [
                'channel' => $channel,
                'name' => $threadInfo->title,
            ]);
        }

        if ($threadInfo->topic !== null) {
            $this->apiCall('conversations.setTopic', [
                'channel' => $channel,
                'topic' => $threadInfo->topic,
            ]);
        }

        if ($threadInfo->isArchived === true) {
            $this->apiCall('conversations.archive', ['channel' => $channel]);
        } elseif ($threadInfo->isArchived === false) {
            $this->apiCall('conversations.unarchive', ['channel' => $channel]);
        }

        return $this->fetchThread($threadId);
    }

    public function fetchChannelInfo(string $channelId): ?ChannelInfo
    {
        $parts = explode(':', $channelId, 3);
        $channel = $parts[1] ?? $parts[0];

        try {
            $response = $this->apiCall('conversations.info', ['channel' => $channel]);
        } catch (AdapterException) {
            return null;
        }

        $channel = $response['channel'] ?? null;

        if ($channel === null) {
            return null;
        }

        return new ChannelInfo(
            id: $channel['id'],
            name: $channel['name'] ?? '',
            topic: $channel['topic']['value'] ?? null,
            isPrivate: $channel['is_private'] ?? false,
        );
    }

    public function getUser(string $userId): ?UserInfo
    {
        $response = $this->apiCall('users.info', ['user' => $userId]);
        $user = $response['user'] ?? null;

        if ($user === null) {
            return null;
        }

        return new UserInfo(
            id: $user['id'],
            name: $user['name'] ?? ($user['profile']['real_name'] ?? ''),
            email: $user['profile']['email'] ?? null,
        );
    }

    public function getAuthorInfo(Author $author): Author
    {
        $response = $this->apiCall('users.info', ['user' => $author->id]);
        $user = $response['user'] ?? null;

        if ($user === null) {
            return $author;
        }

        $localizations = [];
        $profilePicture = $author->profilePicture;

        if (isset($user['profile']['image_512'])) {
            $profilePicture = $user['profile']['image_512'];
        }

        if (isset($user['locale'])) {
            $localizations[] = new LocalizationValue(LocalizationType::Locale, $user['locale']);
        }

        if (isset($user['tz'])) {
            $localizations[] = new LocalizationValue(LocalizationType::Timezone, $user['tz']);
        }

        if ($localizations === [] && $profilePicture === $author->profilePicture) {
            return $author;
        }

        return (
            new Author(
                $author->id,
                $author->name,
                $author->email,
                $author->isMe,
                $author->isBot,
                $profilePicture,
            )
        )->withLocalizations(...$localizations);
    }

    public function openDM(string $userId): ?string
    {
        $response = $this->apiCall('conversations.open', ['users' => $userId]);

        return $response['channel']['id'] ?? null;
    }

    public function getFormatConverter(): ?FormatConverter
    {
        return $this->formatConverter;
    }

    public function initialize(Chat $chat): void
    {
        // Resolve bot user ID
        if ($this->botUserId === null) {
            try {
                $auth = $this->apiCall('auth.test', []);
                $this->botUserId = $auth['user_id'] ?? null;
            } catch (AdapterException) {
                // Will retry on next request
            }
        }
    }

    public function disconnect(): void
    {
        // No persistent connection to close
    }

    public function createResponse(): ?ResponseInterface
    {
        return null;
    }

    public function openModal(string $triggerId, Modal $modal, ?string $contextId = null): ?array
    {
        $view = SlackModalConverter::toSlackView($modal, $contextId);

        $response = $this->apiCall('views.open', [
            'trigger_id' => $triggerId,
            'view' => $view,
        ]);

        return [
            'viewId' => $response['view']['id'] ?? '',
        ];
    }

    public function stream(string $threadId, iterable $textStream, array $options = []): ?SentMessage
    {
        // Slack doesn't support native streaming
        $fullText = '';
        foreach ($textStream as $chunk) {
            $fullText .= $chunk;
        }

        if ($fullText === '') {
            return null;
        }

        return $this->postMessage($threadId, PostableMessage::text($fullText));
    }

    protected function buildMessageParams(PostableMessage $message): array
    {
        if ($message->isCard()) {
            $text = $message->content->getFallbackText();

            return [
                'text' => EmojiResolver::convertPlaceholders($text, 'slack', $this->emojiResolver),
                'blocks' => SlackCards::toBlockKit($message->content),
            ];
        }

        $payload = $this->formatConverter->toSlackPayload($message);

        if (isset($payload['text'])) {
            $payload['text'] = EmojiResolver::convertPlaceholders($payload['text'], 'slack', $this->emojiResolver);
        }

        if (isset($payload['markdown_text'])) {
            $payload['markdown_text'] = EmojiResolver::convertPlaceholders($payload['markdown_text'], 'slack', $this->emojiResolver);
        }

        return $payload;
    }

    public function fetchMedia(Attachment $attachment): StreamInterface
    {
        $url = $attachment->url;

        if ($url === null || $url === '') {
            throw new AdapterException('No URL available for attachment');
        }

        $raw = $this->apiCall('', httpMethod: 'GET', overrideUrl: $url, returnStream: true);

        return $raw['stream'];
    }

    public function rehydrateAttachment(Attachment $attachment): Attachment
    {
        $url = $attachment->url;

        if ($url === null || $url === '') {
            return $attachment;
        }

        return $attachment->withFetchOptions(fetchData: [$this, 'fetchMedia']);
    }

    protected function apiCall(string $method, array $params = [], string $contentType = 'application/json', string $httpMethod = 'POST', ?string $overrideUrl = null, bool $returnStream = false): array
    {
        $factory = $this->psrFactory ?? new Psr17Factory;
        $url = $overrideUrl ?? $this->apiUrl.$method;

        $request = $factory->createRequest($httpMethod, $url)
            ->withHeader('Authorization', "Bearer {$this->botToken}");

        if ($httpMethod !== 'GET') {
            if ($contentType === 'application/x-www-form-urlencoded') {
                $body = http_build_query(array_filter($params, fn ($v): bool => $v !== null));
            } else {
                $body = json_encode(array_filter($params, fn ($v): bool => $v !== null));
            }

            $request = $request
                ->withHeader('Content-Type', $contentType)
                ->withBody($factory->createStream($body));
        }

        $psrResponse = $this->httpClient->sendRequest($request);
        $statusCode = $psrResponse->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            $responseBody = (string) $psrResponse->getBody();
            throw new AdapterException("Slack API returned HTTP {$statusCode}: {$responseBody}");
        }

        if ($returnStream) {
            return ['stream' => $psrResponse->getBody(), 'status' => $statusCode];
        }

        $responseBody = (string) $psrResponse->getBody();
        $data = json_decode($responseBody, true);

        if (! is_array($data)) {
            throw new AdapterException("Invalid JSON response from Slack API: {$method}");
        }

        if (($data['ok'] ?? false) === false) {
            $error = $data['error'] ?? 'unknown_error';

            $authErrors = ['invalid_auth', 'not_authed', 'account_inactive', 'token_revoked', 'token_expired', 'org_login_required', 'ekm_access_denied', 'access_denied', 'no_permission'];
            if (in_array($error, $authErrors, true)) {
                throw new AuthenticationException("Slack API authentication error ({$method}): {$error}");
            }

            throw new AdapterException("Slack API error ({$method}): {$error}");
        }

        return $data;
    }

    protected function parseInteractivePayload(ServerRequestInterface $request): ?array
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if (! str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return null;
        }

        $body = (string) $request->getBody();
        parse_str($body, $params);

        if (! isset($params['payload'])) {
            return null;
        }

        $payload = json_decode($params['payload'], true);

        return is_array($payload) ? $payload : null;
    }

    protected function parseSlackEvent(ServerRequestInterface $request, string $eventType, callable $extract): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return null;
        }

        $event = $payload['event'] ?? [];
        if (($event['type'] ?? '') !== $eventType) {
            return null;
        }

        return $extract($event);
    }
}
