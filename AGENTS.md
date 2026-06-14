# adapter-slack

Slack adapter for bootdesk/chat-sdk-core. Namespace: `BootDesk\ChatSDK\Slack`

## files
- `SlackAdapter` — implements `Adapter` using Slack Web API (chat.postMessage, conversations.replies, etc.)
- `SlackFormatConverter` — Slack mrkdwn ↔ CommonMark AST
- `SlackCards` — Card model → Block Kit layout
- `SlackWebhookVerifier` — HMAC-SHA256 request verification
- `SlackModalConverter` — Modal value objects → Slack Block Kit view payload

## contracts implemented
- `HandlesActions` — `parseAction()` for `block_actions` interactive payloads
- `HandlesSlashCommands` — `parseSlashCommand()` for Slack slash commands
- `HandlesReactions` — `parseReaction()` for `reaction_added`/`reaction_removed` events
- `HandlesModals` — `parseModalSubmit()` for `view_submission`, `parseModalClose()` for `view_closed`
- `HandlesOptionsLoad` — `parseOptionsLoad()` for `block_suggestion`, `respondToOptionsLoad()` returns JSON response
- `HandlesSlackEvents` — `parseAssistantThreadStarted()`, `parseAssistantContextChanged()`, `parseAppHomeOpened()`, `parseMemberJoinedChannel()`
- `SupportsModals` — `openModal()` calls Slack `views.open` API; uses `SlackModalConverter::toSlackView()`
- `SupportsEditMessages` — editMessage via `chat.update`
- `SupportsDeleteMessages` — deleteMessage via `chat.delete`

## registration
`scc/register.php` registers `'slack' => SlackAdapter::class` via `AdapterRegistry`

## constructor
```php
new SlackAdapter(
    string $botToken,
    ClientInterface $httpClient,
    ?string $signingSecret = null,
    string $apiUrl = 'https://slack.com/api/',
    ?Psr17Factory $psrFactory = null,
    ?EmojiResolver $emojiResolver = null,  // defaults to EmojiResolver::default()
);
```

- `EmojiResolver` normalizes incoming reactions (`parseReaction` → `emoji` field) and outgoing reactions (`addReaction`/`removeReaction` → converts to Slack format)
- `{{emoji:name}}` placeholders in message text auto-convert to `:emoji:` Slack format inside `buildMessageParams()`

## thread ID format
`slack:{channel}:{thread_ts}` — e.g. `slack:C123:1234567890.123456`

## webhook flow
1. `verifyWebhook` — handles `url_verification` challenge, verifies HMAC signature
2. `handleWebhook` dispatches in order: Actions → SlashCommands → Modals → OptionsLoad → Reactions → SlackEvents → Messages

## features
- Post/edit/delete messages, Block Kit cards
- Add/remove reactions (reactions.add, reactions.remove)
- Fetch thread replies (conversations.replies), channel info (conversations.info)
- Open DM (conversations.open), get user info (users.info)
- Initialize resolves bot user ID via auth.test
- File uploads via 3-step API: `files.getUploadURLExternal` → binary POST → `files.completeUploadExternal`
- URL-based attachments rendered as image blocks / text links
- Modal forms via `views.open` with `SlackModalConverter`
- External select menus via `block_suggestion` → `respondToOptionsLoad()`
- Streaming: concatenates chunks into single message

## config (laravel)
```php
'slack' => [
    'bot_token' => env('SLACK_BOT_TOKEN'),
    'signing_secret' => env('SLACK_SIGNING_SECRET'),
],
```
