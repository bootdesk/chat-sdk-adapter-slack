<?php

namespace BootDesk\ChatSDK\Slack;

use BootDesk\ChatSDK\Core\Markdown\BaseFormatConverter;
use BootDesk\ChatSDK\Core\PostableMessage;
use League\CommonMark\Node\Block\Document;

class SlackFormatConverter extends BaseFormatConverter
{
    public function toAst(string $mrkdwn): Document
    {
        $markdown = $mrkdwn;

        // User mentions: <@U123|name> -> @name, <@U123> -> @U123
        $markdown = preg_replace('/<@([A-Z0-9_]+)\|([^<>]+)>/', '@$2', $markdown);
        $markdown = preg_replace('/<@([A-Z0-9_]+)>/', '@$1', $markdown);

        // Channel mentions: <#C123|name> -> #name
        $markdown = preg_replace('/<#[A-Z0-9_]+\|([^<>]+)>/', '#$1', $markdown);
        $markdown = preg_replace('/<#([A-Z0-9_]+)>/', '#$1', $markdown);

        // Links: <url|text> -> [text](url)
        $markdown = preg_replace('/<(https?:\/\/[^|<>]+)\|([^<>]+)>/', '[$2]($1)', $markdown);

        // Bare links: <url> -> url
        $markdown = preg_replace('/<(https?:\/\/[^<>]+)>/', '$1', $markdown);

        // Bold: *text* -> **text** (Slack single * for bold)
        $markdown = preg_replace('/(?<![_*\\\\])\*([^*\n]+)\*(?![_*])/', '**$1**', $markdown);

        // Strikethrough: ~text~ -> ~~text~~
        $markdown = preg_replace('/(?<!~)~([^~\n]+)~(?!~)/', '~~$1~~', $markdown);

        return $this->parseMarkdown($markdown);
    }

    public function fromAst(Document $ast): string
    {
        return $this->renderMarkdown($ast);
    }

    public function extractPlainText(string $platformText): string
    {
        $text = $platformText;

        // Strip all Slack-specific formatting to plain text
        $text = preg_replace('/<@([A-Z0-9_]+)\|([^<>]+)>/', '$2', $text);
        $text = preg_replace('/<@([A-Z0-9_]+)>/', '$1', $text);
        $text = preg_replace('/<#[A-Z0-9_]+\|([^<>]+)>/', '$1', $text);
        $text = preg_replace('/<#([A-Z0-9_]+)>/', '$1', $text);
        $text = preg_replace('/<(https?:\/\/[^|<>]+)\|([^<>]+)>/', '$2', $text);
        $text = preg_replace('/<(https?:\/\/[^<>]+)>/', '$1', $text);

        return parent::extractPlainText($text);
    }

    public function renderPostable(PostableMessage $message): string
    {
        if ($message->isCard()) {
            return $message->content->getFallbackText();
        }

        return $this->toMrkdwn((string) $message->content);
    }

    public function toMrkdwn(string $markdown): string
    {
        // **bold** -> *bold*
        $text = preg_replace('/\*\*(.+?)\*\*/', '*$1*', $markdown);

        // Convert @mentions to Slack format: @user -> <@user>
        $text = preg_replace('/(?<![<\w])@(\w+)/', '<@$1>', $text);

        return $text;
    }

    public function toSlackPayload(PostableMessage $message): array
    {
        if ($message->isCard()) {
            return ['text' => $message->content->getFallbackText()];
        }

        $content = (string) $message->content;

        return ['markdown_text' => $this->finalizePayload($content)];
    }

    public function toResponseUrlText(PostableMessage $message): string
    {
        if ($message->isCard()) {
            return $message->content->getFallbackText();
        }

        return $this->toMrkdwn((string) $message->content);
    }

    private function finalizePayload(string $text): string
    {
        return preg_replace('/(?<![<\w])@(\w+)/', '<@$1>', $text);
    }
}
