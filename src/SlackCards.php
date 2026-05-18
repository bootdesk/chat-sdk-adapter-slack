<?php

namespace BootDesk\ChatSDK\Slack;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Cards\Divider;
use BootDesk\ChatSDK\Core\Cards\Image;
use BootDesk\ChatSDK\Core\Cards\Link;
use BootDesk\ChatSDK\Core\Cards\LinkButton;
use BootDesk\ChatSDK\Core\Cards\Section;
use BootDesk\ChatSDK\Core\Cards\Table;
use BootDesk\ChatSDK\Core\Cards\Text;
use BootDesk\ChatSDK\Core\Cards\TextStyle;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link as CommonMarkLink;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text as CommonMarkText;
use League\CommonMark\Parser\MarkdownParser;

class SlackCards
{
    private static bool $usedNativeTable = false;

    private const TABLE_MAX_ROWS = 100;

    private const TABLE_MAX_COLS = 20;

    public static function toBlockKit(Card $card): array
    {
        $blocks = [];
        $elements = [];
        self::$usedNativeTable = false;

        if ($card->getImageUrl() !== null) {
            $blocks[] = [
                'type' => 'image',
                'image_url' => $card->getImageUrl(),
                'alt_text' => $card->getImageAlt() ?: ($card->getHeader() ?? 'Image'),
            ];
        }

        if ($card->getHeader() !== null) {
            $blocks[] = [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => $card->getHeader(),
                ],
            ];
        }

        foreach ($card->getChildren() as $child) {
            if ($child instanceof Text) {
                $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => self::markdownToMrkdwn(match ($child->style) {
                    TextStyle::Bold => "**{$child->content}**",
                    TextStyle::Muted => "_{$child->content}_",
                    default => $child->content,
                })]];
            } elseif ($child instanceof Divider) {
                $blocks[] = ['type' => 'divider'];
            } elseif ($child instanceof Image) {
                $blocks[] = [
                    'type' => 'image',
                    'image_url' => $child->url,
                    'alt_text' => $child->alt ?: ($card->getHeader() ?? 'Image'),
                ];
            } elseif ($child instanceof Link) {
                $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "<{$child->url}|{$child->label}>"]];
            } elseif ($child instanceof Table) {
                $blocks[] = self::convertTableToSlack($child);
            } elseif ($child instanceof LinkButton) {
                $elements[] = self::convertLinkButton($child);
            }
        }

        foreach ($card->getSections() as $section) {
            $sectionBlocks = self::convertSection($section);
            foreach ($sectionBlocks as $block) {
                $blocks[] = $block;
            }
        }

        $buttons = $card->getButtons();
        foreach ($buttons as $button) {
            $elements[] = self::convertButton($button);
        }

        if ($elements !== []) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => $elements,
            ];
        }

        return $blocks;
    }

    private static function convertSection(Section $section): array
    {
        $blocks = [];

        if ($section->getText() !== null) {
            $text = self::markdownToMrkdwn($section->getText());
            $fields = $section->getFields();

            if (count($fields) > 0) {
                $fieldObjects = [];
                $fieldObjects[] = ['type' => 'mrkdwn', 'text' => $text];
                foreach ($fields as $label => $value) {
                    $fieldObjects[] = [
                        'type' => 'mrkdwn',
                        'text' => '*'.self::markdownToMrkdwn($label)."*\n".self::markdownToMrkdwn($value),
                    ];
                }
                $blocks[] = ['type' => 'section', 'fields' => $fieldObjects];
            } else {
                $blocks[] = [
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => $text],
                ];
            }
        } else {
            $fields = $section->getFields();
            if (count($fields) > 0) {
                $fieldObjects = [];
                foreach ($fields as $label => $value) {
                    $fieldObjects[] = [
                        'type' => 'mrkdwn',
                        'text' => '*'.self::markdownToMrkdwn($label)."*\n".self::markdownToMrkdwn($value),
                    ];
                }
                $blocks[] = ['type' => 'section', 'fields' => $fieldObjects];
            }
        }

        return $blocks;
    }

    private static function convertButton(Button $button): array
    {
        $element = [
            'type' => 'button',
            'text' => [
                'type' => 'plain_text',
                'text' => $button->label,
                'emoji' => true,
            ],
            'action_id' => $button->actionId,
        ];

        if ($button->data !== []) {
            $element['value'] = json_encode($button->data);
        }

        if ($button->style->value !== 'secondary') {
            $element['style'] = $button->style->value;
        }

        return $element;
    }

    private static function markdownToMrkdwn(string $markdown): string
    {
        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $parser = new MarkdownParser($environment);
        $ast = $parser->parse($markdown);

        $walker = $ast->walker();
        $result = '';

        while ($event = $walker->next()) {
            $node = $event->getNode();

            if ($event->isEntering()) {
                if ($node instanceof Strong) {
                    $result .= '*';
                } elseif ($node instanceof Emphasis) {
                    $result .= '_';
                } elseif ($node instanceof Code) {
                    $result .= '`'.$node->getLiteral().'`';
                } elseif ($node instanceof CommonMarkLink) {
                    $result .= '<'.$node->getUrl().'|';
                } elseif ($node instanceof CommonMarkText) {
                    $result .= $node->getLiteral();
                }
            } elseif ($node instanceof Strong) {
                $result .= '*';
            } elseif ($node instanceof Emphasis) {
                $result .= '_';
            } elseif ($node instanceof CommonMarkLink) {
                $result .= '>';
            } elseif ($node instanceof Paragraph) {
                $result .= "\n";
            }
        }

        return trim($result);
    }

    private static function convertTableToSlack(Table $table): array
    {
        $numRows = count($table->rows);
        $numCols = count($table->headers);

        if (
            self::$usedNativeTable
            || $numRows > self::TABLE_MAX_ROWS
            || $numCols > self::TABLE_MAX_COLS
        ) {
            // Fall back to ASCII table in a code block.
            // Slack section text limit is 3000 chars for mrkdwn.
            $text = Table::renderAsText($table);
            $lines = explode("\n", $text);
            $maxLines = count($lines);

            $preformatted = "```\n```";
            for (; $maxLines > 1; $maxLines -= 5) {
                $truncated = implode("\n", array_slice($lines, 0, $maxLines));
                $omitted = count($lines) - $maxLines;
                $suffix = $omitted > 0 ? "\n... and {$omitted} more rows" : '';
                $preformatted = "```\n{$truncated}{$suffix}\n```";
                if (strlen($preformatted) <= 3000) {
                    break;
                }
            }

            return ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $preformatted]];
        }

        self::$usedNativeTable = true;

        $toRichText = fn (string $text): array => [
            'type' => 'rich_text',
            'elements' => [
                ['type' => 'rich_text_section', 'elements' => [['type' => 'text', 'text' => $text ?: ' ']]],
            ],
        ];

        $headerRow = array_map($toRichText, $table->headers);
        $dataRows = array_map(fn (array $row): array => array_map($toRichText, $row), $table->rows);

        return [
            'type' => 'table',
            'rows' => [$headerRow, ...$dataRows],
        ];
    }

    private static function convertLinkButton(LinkButton $button): array
    {
        $element = [
            'type' => 'button',
            'text' => [
                'type' => 'plain_text',
                'text' => $button->label,
                'emoji' => true,
            ],
            'url' => $button->url,
            'action_id' => 'link_'.substr(md5($button->url), 0, 200),
        ];

        if ($button->style->value !== 'secondary') {
            $element['style'] = $button->style->value;
        }

        return $element;
    }
}
