<?php

namespace Tests\Feature\Assets\CategoryNavigation;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Minimal XPath helper over a rendered page (PHP 8.2+ compatible; the
 * project does not ship symfony/dom-crawler).
 */
final class HtmlPage
{
    private DOMXPath $xpath;

    public function __construct(string $html)
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->xpath = new DOMXPath($document);
    }

    /** XPath predicate: element has CSS class $class. */
    public static function hasClass(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    /** @return list<DOMElement> */
    public function all(string $xpath, ?DOMElement $context = null): array
    {
        $nodes = [];
        foreach ($this->xpath->query($xpath, $context) as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    public function first(string $xpath, ?DOMElement $context = null): ?DOMElement
    {
        return $this->all($xpath, $context)[0] ?? null;
    }

    public function count(string $xpath): int
    {
        return count($this->all($xpath));
    }

    public static function text(?DOMElement $element): string
    {
        return $element ? trim(preg_replace('/\s+/u', ' ', $element->textContent)) : '';
    }
}
