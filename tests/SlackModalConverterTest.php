<?php

namespace BootDesk\ChatSDK\Slack\Tests;

use BootDesk\ChatSDK\Core\Modals\ExternalSelect;
use BootDesk\ChatSDK\Core\Modals\Modal;
use BootDesk\ChatSDK\Core\Modals\RadioSelect;
use BootDesk\ChatSDK\Core\Modals\Select;
use BootDesk\ChatSDK\Core\Modals\SelectOption;
use BootDesk\ChatSDK\Core\Modals\TextInput;
use BootDesk\ChatSDK\Slack\SlackModalConverter;
use PHPUnit\Framework\TestCase;

class SlackModalConverterTest extends TestCase
{
    public function test_converts_simple_modal(): void
    {
        $modal = new Modal(
            callbackId: 'feedback',
            title: 'Feedback',
            submitLabel: 'Send',
            closeLabel: 'Cancel',
        );

        $view = SlackModalConverter::toSlackView($modal);

        $this->assertSame('modal', $view['type']);
        $this->assertSame('feedback', $view['callback_id']);
        $this->assertSame('Feedback', $view['title']['text']);
        $this->assertSame('Send', $view['submit']['text']);
        $this->assertSame('Cancel', $view['close']['text']);
        $this->assertEmpty($view['blocks']);
    }

    public function test_converts_modal_with_text_input(): void
    {
        $modal = new Modal(
            callbackId: 'test',
            title: 'Test',
            children: [
                new TextInput(id: 'name', label: 'Name', placeholder: 'Your name', multiline: true, optional: true, maxLength: 100),
            ],
        );

        $view = SlackModalConverter::toSlackView($modal);

        $this->assertCount(1, $view['blocks']);
        $block = $view['blocks'][0];
        $this->assertSame('input', $block['type']);
        $this->assertSame('name', $block['block_id']);
        $this->assertTrue($block['optional']);
        $this->assertSame('plain_text_input', $block['element']['type']);
        $this->assertTrue($block['element']['multiline']);
        $this->assertSame(100, $block['element']['max_length']);
    }

    public function test_converts_modal_with_select(): void
    {
        $modal = new Modal(
            callbackId: 'picker',
            title: 'Pick',
            children: [
                new Select(
                    id: 'color',
                    label: 'Color',
                    options: [
                        new SelectOption(label: 'Red', value: 'red'),
                        new SelectOption(label: 'Blue', value: 'blue', description: 'Ocean blue'),
                    ],
                    initialOption: 'red',
                    placeholder: 'Choose a color',
                ),
            ],
        );

        $view = SlackModalConverter::toSlackView($modal);

        $block = $view['blocks'][0];
        $this->assertSame('static_select', $block['element']['type']);
        $this->assertCount(2, $block['element']['options']);
        $this->assertSame('Red', $block['element']['options'][0]['text']['text']);
        $this->assertSame('Ocean blue', $block['element']['options'][1]['description']['text']);
        $this->assertSame('red', $block['element']['initial_option']['value']);
    }

    public function test_converts_modal_with_external_select(): void
    {
        $modal = new Modal(
            callbackId: 'ext',
            title: 'External',
            children: [
                new ExternalSelect(
                    id: 'fruit',
                    label: 'Fruit',
                    placeholder: 'Type to search...',
                    minQueryLength: 2,
                    initialOption: new SelectOption(label: 'Apple', value: 'apple'),
                ),
            ],
        );

        $view = SlackModalConverter::toSlackView($modal);

        $block = $view['blocks'][0];
        $this->assertSame('external_select', $block['element']['type']);
        $this->assertSame(2, $block['element']['min_query_length']);
        $this->assertSame('Apple', $block['element']['initial_option']['text']['text']);
    }

    public function test_converts_modal_with_radio_select(): void
    {
        $modal = new Modal(
            callbackId: 'radio',
            title: 'Radio',
            children: [
                new RadioSelect(
                    id: 'size',
                    label: 'Size',
                    options: [
                        new SelectOption(label: 'Small', value: 's'),
                        new SelectOption(label: 'Large', value: 'l'),
                    ],
                    initialOption: 's',
                ),
            ],
        );

        $view = SlackModalConverter::toSlackView($modal);

        $block = $view['blocks'][0];
        $this->assertSame('radio_buttons', $block['element']['type']);
        $this->assertCount(2, $block['element']['options']);
    }

    public function test_passes_context_id_as_private_metadata(): void
    {
        $modal = new Modal(callbackId: 'test', title: 'Test');
        $view = SlackModalConverter::toSlackView($modal, 'ctx123');

        $this->assertSame('ctx123', $view['private_metadata']);
    }

    public function test_title_truncated_to_24_chars(): void
    {
        $modal = new Modal(callbackId: 'test', title: str_repeat('A', 50));
        $view = SlackModalConverter::toSlackView($modal);

        $this->assertSame(24, strlen($view['title']['text']));
    }

    public function test_notify_on_close(): void
    {
        $modal = new Modal(callbackId: 'test', title: 'Test', notifyOnClose: true);
        $view = SlackModalConverter::toSlackView($modal);

        $this->assertTrue($view['notify_on_close']);
    }

    public function test_radio_select_limited_to_10_options(): void
    {
        $options = [];
        for ($i = 1; $i <= 15; $i++) {
            $options[] = new SelectOption(label: "Option $i", value: "opt$i");
        }

        $modal = new Modal(
            callbackId: 'big',
            title: 'Big',
            children: [new RadioSelect(id: 'big', label: 'Big', options: $options)],
        );

        $view = SlackModalConverter::toSlackView($modal);
        $this->assertCount(10, $view['blocks'][0]['element']['options']);
    }
}
