<?php

namespace BootDesk\ChatSDK\Slack\Tests;

use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Slack\SlackFormatConverter;
use PHPUnit\Framework\TestCase;

class SlackFormatConverterTest extends TestCase
{
    private SlackFormatConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new SlackFormatConverter;
    }

    public function test_user_mention_with_name(): void
    {
        $ast = $this->converter->toAst('Hello <@U12345|john>');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('@john', $markdown);
    }

    public function test_user_mention_without_name(): void
    {
        $ast = $this->converter->toAst('Hello <@U12345>');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('@U12345', $markdown);
    }

    public function test_channel_mention(): void
    {
        $ast = $this->converter->toAst('Posted in <#C12345|general>');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('#general', $markdown);
    }

    public function test_link_with_text(): void
    {
        $ast = $this->converter->toAst('Check <https://example.com|this link>');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('[this link](https://example.com)', $markdown);
    }

    public function test_bare_link(): void
    {
        $ast = $this->converter->toAst('Visit <https://example.com>');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('https://example.com', $markdown);
    }

    public function test_slack_bold_to_markdown(): void
    {
        $ast = $this->converter->toAst('This is *bold* text');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('**bold**', $markdown);
    }

    public function test_slack_strikethrough_to_markdown(): void
    {
        $ast = $this->converter->toAst('This is ~deleted~ text');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('~~deleted~~', $markdown);
    }

    public function test_extract_plain_text(): void
    {
        $text = $this->converter->extractPlainText('Hello <@U123|john> check <https://example.com|this>');
        $this->assertStringContainsString('john', $text);
        $this->assertStringNotContainsString('<@U123', $text);
    }

    public function test_to_mrkdwn_converts_bold(): void
    {
        $mrkdwn = $this->converter->toMrkdwn('This is **bold** text');
        $this->assertStringContainsString('*bold*', $mrkdwn);
        $this->assertStringNotContainsString('**bold**', $mrkdwn);
    }

    public function test_to_mrkdwn_converts_mentions(): void
    {
        $mrkdwn = $this->converter->toMrkdwn('Hello @john');
        $this->assertStringContainsString('<@john>', $mrkdwn);
    }

    public function test_to_slack_payload_with_text(): void
    {
        $message = PostableMessage::text('Hello **world**');
        $payload = $this->converter->toSlackPayload($message);

        $this->assertArrayHasKey('markdown_text', $payload);
        $this->assertStringContainsString('Hello **world**', $payload['markdown_text']);
    }

    public function test_to_slack_payload_with_card(): void
    {
        $card = Card::make()->header('Test');
        $message = PostableMessage::card($card);
        $payload = $this->converter->toSlackPayload($message);

        $this->assertArrayHasKey('text', $payload);
        $this->assertSame('Test', $payload['text']);
    }

    public function test_to_response_url_text(): void
    {
        $message = PostableMessage::text('Hello **world**');
        $text = $this->converter->toResponseUrlText($message);

        $this->assertStringContainsString('*world*', $text);
    }

    public function test_roundtrip_bold(): void
    {
        $ast = $this->converter->toAst('*bold text*');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('bold text', $markdown);
    }

    public function test_render_as_gfm_from_slack_mrkdwn(): void
    {
        $result = $this->converter->renderAsGFM('Hello <@U12345|john> check <https://example.com|this link>');

        $this->assertStringContainsString('@john', $result);
        $this->assertStringContainsString('[this link](https://example.com)', $result);
    }

    public function test_render_as_gfm_converts_slack_bold(): void
    {
        $result = $this->converter->renderAsGFM('*bold*');

        $this->assertStringContainsString('**bold**', $result);
    }

    public function test_render_as_gfm_from_ast(): void
    {
        $ast = $this->converter->toAst('*bold*');
        $result = $this->converter->renderAsGFM($ast);

        $this->assertStringContainsString('**bold**', $result);
    }
}
