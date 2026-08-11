<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allowlist sanitiser for rich text written in the script editor.
 *
 * The editor is a contenteditable, which means the browser will hand us
 * whatever the writer pasted -- Word markup, style attributes, tracking
 * pixels, and given a hostile paste, a <script> tag or an onerror handler.
 * Script bodies are rendered back to other staff, so anything that survives
 * this function is stored XSS.
 *
 * Filtering in the browser is not a control: the endpoint accepts JSON and can
 * be called directly. This runs server-side on every save, and it is the only
 * thing standing between a paste and the database.
 *
 * The approach is an allowlist, never a blocklist. Tags not named here are
 * unwrapped (their text survives, the tag does not) rather than dropped, so a
 * paste from Google Docs loses its <span style> soup but keeps the words.
 */
class Html
{
    /**
     * Tags the editor can produce, mapped to the attributes each may keep.
     *
     * Everything the toolbar offers is here and nothing else. No images, no
     * tables, no headings -- a section already has a heading of its own.
     */
    private const ALLOWED = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'em' => [],
        'u' => [],
        's' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'a' => ['href'],
    ];

    /** Only these can appear in an href. javascript: and data: are the point. */
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Tags the browser emits that mean the same as one we allow. Normalising
     * them keeps the stored markup small and predictable.
     */
    private const ALIASES = [
        'b' => 'strong',
        'i' => 'em',
        'div' => 'p',
        'strike' => 's',
        'del' => 's',
    ];

    public static function sanitise(?string $html): ?string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return null;
        }

        $document = new DOMDocument;

        /*
         * Parse as a UTF-8 fragment. The meta charset is the documented way to
         * stop DOMDocument assuming ISO-8859-1 and mangling anything non-ASCII
         * -- Tamil script in these scripts, for one. libxml errors are muted
         * because we are deliberately feeding it fragments.
         */
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="UTF-8"?><body>'.$html.'</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body) {
            return null;
        }

        self::clean($body, $document);

        $clean = '';

        foreach ($body->childNodes as $child) {
            $clean .= $document->saveHTML($child);
        }

        $clean = trim($clean);

        // A paste that reduces to nothing but empty tags is nothing.
        return trim(strip_tags($clean)) === '' && ! str_contains($clean, '<br')
            ? null
            : ($clean === '' ? null : $clean);
    }

    /**
     * Strip the node's disallowed children in place.
     *
     * Iterates over a snapshot of childNodes because unwrapping mutates the
     * live NodeList underneath the loop.
     */
    private static function clean(DOMNode $node, DOMDocument $document): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                self::cleanElement($child, $document);

                continue;
            }

            // Text nodes stay. Comments, CDATA and processing instructions go:
            // none of them can be typed, so their presence means a paste.
            if ($child->nodeType !== XML_TEXT_NODE) {
                $node->removeChild($child);
            }
        }
    }

    private static function cleanElement(DOMElement $element, DOMDocument $document): void
    {
        $tag = strtolower($element->tagName);
        $tag = self::ALIASES[$tag] ?? $tag;

        /*
         * script and style are unwrapped like anything else would be, which
         * would spill their source into the document as text. They are the one
         * case where the contents must go with the tag.
         */
        if (in_array($tag, ['script', 'style'], true)) {
            $element->parentNode?->removeChild($element);

            return;
        }

        self::clean($element, $document);

        if (! isset(self::ALLOWED[$tag])) {
            self::unwrap($element);

            return;
        }

        // Rename in place when the tag was an alias (b -> strong).
        if ($tag !== strtolower($element->tagName)) {
            $renamed = $document->createElement($tag);

            while ($element->firstChild) {
                $renamed->appendChild($element->firstChild);
            }

            $element->parentNode?->replaceChild($renamed, $element);
            $element = $renamed;
        }

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (! in_array($name, self::ALLOWED[$tag], true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if ($name === 'href' && ! self::isSafeUrl($attribute->nodeValue)) {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        // A link that lost its href is no longer a link.
        if ($tag === 'a' && ! $element->hasAttribute('href')) {
            self::unwrap($element);

            return;
        }

        if ($tag === 'a') {
            // Anything opening a new tab needs this or the new page can reach
            // back through window.opener.
            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /** Replace an element with its own children, keeping the text. */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if (! $parent) {
            return;
        }

        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private static function isSafeUrl(?string $url): bool
    {
        $url = trim((string) $url);

        if ($url === '') {
            return false;
        }

        // Relative and anchor links never carry a scheme, so they are safe by
        // construction. Everything else must name a scheme we trust.
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, self::SAFE_SCHEMES, true);
    }
}
