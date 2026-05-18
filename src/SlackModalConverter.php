<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Slack;

use BootDesk\ChatSDK\Core\Modals\ExternalSelect;
use BootDesk\ChatSDK\Core\Modals\Modal;
use BootDesk\ChatSDK\Core\Modals\RadioSelect;
use BootDesk\ChatSDK\Core\Modals\Select;
use BootDesk\ChatSDK\Core\Modals\SelectOption;
use BootDesk\ChatSDK\Core\Modals\TextInput;

class SlackModalConverter
{
    public static function toSlackView(Modal $modal, ?string $contextId = null): array
    {
        $view = [
            'type' => 'modal',
            'callback_id' => $modal->callbackId,
            'title' => ['type' => 'plain_text', 'text' => mb_substr($modal->title, 0, 24)],
            'submit' => ['type' => 'plain_text', 'text' => $modal->submitLabel ?? 'Submit'],
            'close' => ['type' => 'plain_text', 'text' => $modal->closeLabel ?? 'Cancel'],
            'blocks' => array_map([self::class, 'childToBlock'], $modal->children),
        ];

        if ($contextId !== null) {
            $view['private_metadata'] = $contextId;
        }

        if ($modal->notifyOnClose !== null) {
            $view['notify_on_close'] = $modal->notifyOnClose;
        }

        return $view;
    }

    private static function childToBlock(TextInput|Select|ExternalSelect|RadioSelect $child): array
    {
        return match (true) {
            $child instanceof TextInput => self::textInputToBlock($child),
            $child instanceof Select => self::selectToBlock($child),
            $child instanceof ExternalSelect => self::externalSelectToBlock($child),
            $child instanceof RadioSelect => self::radioSelectToBlock($child),
        };
    }

    private static function textInputToBlock(TextInput $input): array
    {
        $element = [
            'type' => 'plain_text_input',
            'action_id' => $input->id,
            'multiline' => $input->multiline,
        ];

        if ($input->placeholder !== null) {
            $element['placeholder'] = ['type' => 'plain_text', 'text' => $input->placeholder];
        }
        if ($input->initialValue !== null) {
            $element['initial_value'] = $input->initialValue;
        }
        if ($input->maxLength !== null) {
            $element['max_length'] = $input->maxLength;
        }

        return [
            'type' => 'input',
            'block_id' => $input->id,
            'optional' => $input->optional,
            'label' => ['type' => 'plain_text', 'text' => $input->label],
            'element' => $element,
        ];
    }

    private static function selectToBlock(Select $select): array
    {
        $options = array_map([self::class, 'optionToSlack'], $select->options);

        $element = [
            'type' => 'static_select',
            'action_id' => $select->id,
            'options' => $options,
        ];

        if ($select->placeholder !== null) {
            $element['placeholder'] = ['type' => 'plain_text', 'text' => $select->placeholder];
        }

        if ($select->initialOption !== null) {
            foreach ($options as $opt) {
                if ($opt['value'] === $select->initialOption) {
                    $element['initial_option'] = $opt;
                    break;
                }
            }
        }

        return [
            'type' => 'input',
            'block_id' => $select->id,
            'optional' => $select->optional,
            'label' => ['type' => 'plain_text', 'text' => $select->label],
            'element' => $element,
        ];
    }

    private static function externalSelectToBlock(ExternalSelect $select): array
    {
        $element = [
            'type' => 'external_select',
            'action_id' => $select->id,
        ];

        if ($select->placeholder !== null) {
            $element['placeholder'] = ['type' => 'plain_text', 'text' => $select->placeholder];
        }
        if ($select->minQueryLength !== null) {
            $element['min_query_length'] = $select->minQueryLength;
        }
        if ($select->initialOption instanceof SelectOption) {
            $element['initial_option'] = self::optionToSlack($select->initialOption);
        }

        return [
            'type' => 'input',
            'block_id' => $select->id,
            'optional' => $select->optional,
            'label' => ['type' => 'plain_text', 'text' => $select->label],
            'element' => $element,
        ];
    }

    private static function radioSelectToBlock(RadioSelect $radioSelect): array
    {
        $options = array_map(fn (SelectOption $opt): array => [
            'text' => ['type' => 'mrkdwn', 'text' => $opt->label],
            'value' => $opt->value,
            ...($opt->description !== null ? ['description' => ['type' => 'mrkdwn', 'text' => $opt->description]] : []),
        ], array_slice($radioSelect->options, 0, 10));

        $element = [
            'type' => 'radio_buttons',
            'action_id' => $radioSelect->id,
            'options' => $options,
        ];

        if ($radioSelect->initialOption !== null) {
            foreach ($options as $opt) {
                if ($opt['value'] === $radioSelect->initialOption) {
                    $element['initial_option'] = $opt;
                    break;
                }
            }
        }

        return [
            'type' => 'input',
            'block_id' => $radioSelect->id,
            'optional' => $radioSelect->optional,
            'label' => ['type' => 'plain_text', 'text' => $radioSelect->label],
            'element' => $element,
        ];
    }

    private static function optionToSlack(SelectOption $option): array
    {
        $result = [
            'text' => ['type' => 'plain_text', 'text' => $option->label],
            'value' => $option->value,
        ];

        if ($option->description !== null) {
            $result['description'] = ['type' => 'plain_text', 'text' => $option->description];
        }

        return $result;
    }
}
