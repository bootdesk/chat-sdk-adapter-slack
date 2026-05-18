<?php

namespace BootDesk\ChatSDK\Slack\Tests;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\ButtonStyle;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Slack\SlackCards;
use PHPUnit\Framework\TestCase;

class SlackCardsTest extends TestCase
{
    public function test_card_with_header(): void
    {
        $card = Card::make()->header('Deploy Ready');
        $blocks = SlackCards::toBlockKit($card);

        $this->assertCount(1, $blocks);
        $this->assertSame('header', $blocks[0]['type']);
        $this->assertSame('Deploy Ready', $blocks[0]['text']['text']);
    }

    public function test_card_with_section_text(): void
    {
        $card = Card::make()->section(fn ($s) => $s->text('Hello **world**'));
        $blocks = SlackCards::toBlockKit($card);

        $this->assertCount(1, $blocks);
        $this->assertSame('section', $blocks[0]['type']);
        $this->assertSame('mrkdwn', $blocks[0]['text']['type']);
        $this->assertStringContainsString('*world*', $blocks[0]['text']['text']);
    }

    public function test_card_with_fields(): void
    {
        $card = Card::make()->section(fn ($s) => $s->fields([
            'Status' => 'Deployed',
            'Version' => '1.2.3',
        ]));
        $blocks = SlackCards::toBlockKit($card);

        $this->assertCount(1, $blocks);
        $this->assertSame('section', $blocks[0]['type']);
        $this->assertCount(2, $blocks[0]['fields']);
    }

    public function test_card_with_buttons(): void
    {
        $card = Card::make()
            ->header('Actions')
            ->actions([
                Button::primary('Approve', 'approve'),
                Button::danger('Reject', 'reject'),
            ]);
        $blocks = SlackCards::toBlockKit($card);

        // header + actions
        $this->assertCount(2, $blocks);

        $actionsBlock = $blocks[1];
        $this->assertSame('actions', $actionsBlock['type']);
        $this->assertCount(2, $actionsBlock['elements']);

        $this->assertSame('primary', $actionsBlock['elements'][0]['style']);
        $this->assertSame('danger', $actionsBlock['elements'][1]['style']);
    }

    public function test_card_with_image(): void
    {
        $card = Card::make()
            ->header('Screenshot')
            ->image('https://example.com/img.png', 'Alt text');
        $blocks = SlackCards::toBlockKit($card);

        // header + image
        $this->assertCount(2, $blocks);
        $this->assertSame('image', $blocks[1]['type']);
        $this->assertSame('https://example.com/img.png', $blocks[1]['image_url']);
    }

    public function test_button_with_data(): void
    {
        $card = Card::make()->actions([
            new Button('Click', 'click', ButtonStyle::Primary, ['key' => 'val']),
        ]);
        $blocks = SlackCards::toBlockKit($card);

        $button = $blocks[0]['elements'][0];
        $this->assertSame('click', $button['action_id']);
        $this->assertSame('{"key":"val"}', $button['value']);
    }

    public function test_full_card(): void
    {
        $card = Card::make()
            ->header('Deployment')
            ->section(fn ($s) => $s->text('Build completed'))
            ->section(fn ($s) => $s->fields(['Env' => 'prod', 'Region' => 'us-east-1']))
            ->actions([Button::primary('Deploy', 'deploy')]);

        $blocks = SlackCards::toBlockKit($card);

        // header + section(text) + section(fields) + actions
        $this->assertCount(4, $blocks);
        $this->assertSame('header', $blocks[0]['type']);
        $this->assertSame('section', $blocks[1]['type']);
        $this->assertSame('section', $blocks[2]['type']);
        $this->assertSame('actions', $blocks[3]['type']);
    }

    public function test_header_plain_text(): void
    {
        $card = Card::make()->header('Hello');
        $blocks = SlackCards::toBlockKit($card);

        $this->assertSame('plain_text', $blocks[0]['text']['type']);
        $this->assertSame('Hello', $blocks[0]['text']['text']);
        $this->assertArrayNotHasKey('emoji', $blocks[0]['text']);
    }

    public function test_button_has_emoji(): void
    {
        $card = Card::make()->actions([Button::primary('Go', 'go')]);
        $blocks = SlackCards::toBlockKit($card);

        $this->assertArrayHasKey('emoji', $blocks[0]['elements'][0]['text']);
        $this->assertTrue($blocks[0]['elements'][0]['text']['emoji']);
    }

    public function test_link_button_with_style(): void
    {
        $card = Card::make()
            ->linkButton('Dashboard', 'https://example.com', ButtonStyle::Primary);
        $blocks = SlackCards::toBlockKit($card);

        $this->assertSame('actions', $blocks[0]['type']);
        $this->assertSame('https://example.com', $blocks[0]['elements'][0]['url']);
        $this->assertSame('primary', $blocks[0]['elements'][0]['style']);
        $this->assertArrayHasKey('emoji', $blocks[0]['elements'][0]['text']);
    }

    public function test_table_renders_native_block(): void
    {
        $card = Card::make()->table(
            ['Name', 'Value'],
            [['A', '1'], ['B', '2']],
        );
        $blocks = SlackCards::toBlockKit($card);

        $this->assertSame('table', $blocks[0]['type']);
        $this->assertCount(3, $blocks[0]['rows']); // header + 2 data rows
        $this->assertSame('rich_text', $blocks[0]['rows'][0][0]['type']);
        $this->assertSame('Name', $blocks[0]['rows'][0][0]['elements'][0]['elements'][0]['text']);
    }

    public function test_table_exceeding_rows_falls_back_to_code_block(): void
    {
        $rows = [];
        for ($i = 0; $i < 105; $i++) {
            $rows[] = ["Row {$i}", 'val'];
        }
        $card = Card::make()->table(['Name', 'Value'], $rows);
        $blocks = SlackCards::toBlockKit($card);

        $this->assertSame('section', $blocks[0]['type']);
        $this->assertSame('mrkdwn', $blocks[0]['text']['type']);
        $this->assertStringContainsString('```', $blocks[0]['text']['text']);
    }

    public function test_very_large_table_truncates_text(): void
    {
        $rows = [];
        for ($i = 0; $i < 105; $i++) {
            $rows[] = ["Row {$i} with extra padding ".str_repeat('x', 40), 'value '.str_repeat('y', 40)];
        }
        $card = Card::make()->table(['Name', 'Value'], $rows);
        $blocks = SlackCards::toBlockKit($card);

        $this->assertStringContainsString('more rows', $blocks[0]['text']['text']);
        $this->assertLessThanOrEqual(3100, strlen($blocks[0]['text']['text']));
    }

    public function test_second_table_falls_back_to_code_block(): void
    {
        $card = Card::make()
            ->table(['A'], [['1']])
            ->table(['B'], [['2']]);
        $blocks = SlackCards::toBlockKit($card);

        $this->assertSame('table', $blocks[0]['type'], 'First table should be native');
        $this->assertSame('section', $blocks[1]['type'], 'Second table should be ASCII fallback');
    }
}
