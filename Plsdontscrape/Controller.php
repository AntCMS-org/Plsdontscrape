<?php

declare(strict_types=1);

/**
 * Copyright 2026 AntCMS
 */

namespace AntCMS\Plugins\Plsdontscrape;

use AntCMS\AbstractPlugin;
use AntCMS\AntCMS;
use AntCMS\Event;
use AntCMS\HookController;
use Flight;

class Controller extends AbstractPlugin
{
    private bool $allowDevAgents = true;
    private bool $allowAiScraping = false;
    private bool $poisonMode = true;

    private array $badUserAgents = [
        "", // Empty on purpose
        "Mozilla",
    ];

    // User agents related to development.
    private array $developerAgents = [
        "wget",
        "curl",
        "libcurl",
    ];

    // List is focused on scaping for AI training, not users prompting an AI agent to research something.
    private array $aiScrapers = [
        "GPTBot",
        "ClaudeBot",
        "anthropic-ai",
    ];

    public function __construct()
    {
        if (!$this->shouldTarget()) {
            return;
        }

        if ($this->poisonMode) {
            HookController::registerCallback('onAfterCacheHit', $this->scrambleContent(...));
        } else {
            HookController::registerCallback('onAfterContentHit', $this->performBlock(...));
        }
    }

    private function shouldTarget(): bool
    {
        $userAgent = Flight::request()->user_agent;

        if (in_array($userAgent, $this->badUserAgents)) {
            return true;
        }

        if (in_array($userAgent, $this->developerAgents) && !$this->allowDevAgents) {
            return true;
        }

        if (!$this->allowAiScraping) {
            foreach ($this->aiScrapers as $aiScraper) {
                if (str_contains($userAgent, (string) $aiScraper)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function performBlock(Event $event): void
    {
        AntCMS::renderException(403);
    }

    /**
     * Takes markdown content and scrambles it if a scraper is detected to poison the data.
     */
    public function scrambleContent(Event $event): Event
    {
        $params = $event->getParameters();
        $event->setParameters(['some', 'values']);
        $result = $params['result'] ?? '';

        $params['result'] = $this->scramble($result);
        $event->setParameters($params);
        return $event;
    }

    public function scramble(string $html): string
    {
        $domDocument = new \DOMDocument();

        libxml_use_internal_errors(true);

        $domDocument->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();

        $this->scrambleNode($domDocument);

        return $domDocument->saveHTML();
    }

    /**
     * Recursively visit every node.
     */
    private function scrambleNode(\DOMNode $domNode): void
    {
        // Skip these elements completely.
        if ($domNode instanceof \DOMElement) {
            $skip = [
                'script',
                'style',
            ];

            if (in_array(strtolower($domNode->tagName), $skip, true)) {
                return;
            }

            foreach (['alt', 'title'] as $attribute) {
                if ($domNode->hasAttribute($attribute)) {
                    $domNode->setAttribute(
                        $attribute,
                        $this->scrambleText($domNode->getAttribute($attribute)),
                    );
                }
            }
        }

        if ($domNode->nodeType === XML_TEXT_NODE) {
            $domNode->nodeValue = $this->scrambleText($domNode->nodeValue);
            return;
        }

        foreach ($domNode->childNodes as $child) {
            $this->scrambleNode($child);
        }
    }

    /**
     * Scramble a plain text string.
     */
    private function scrambleText(string $text): string
    {
        $chars = mb_str_split($text);

        $length = count($chars);

        for ($i = 0; $i < $length; $i++) {

            if (!$this->isMovable($chars[$i])) {
                continue;
            }

            $tries = 100;

            while ($tries--) {

                $offset = random_int(-25, 25);
                $j = $i + $offset;

                if (!isset($chars[$j])) {
                    continue;
                }

                if (!$this->isMovable($chars[$j])) {
                    continue;
                }

                [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
                break;
            }
        }

        return implode('', $chars);
    }

    /**
     * Only move letters and numbers.
     */
    private function isMovable(string $char): bool
    {
        return preg_match('/^[\p{L}\p{N}]$/u', $char) === 1;
    }
}
