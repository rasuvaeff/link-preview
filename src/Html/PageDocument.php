<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Html;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ValueError;

/**
 * Read-only view of an HTML page: its title, `<meta>`, `<link>` and `<img>`
 * elements. The markup is parsed, never executed — no scripts, no external
 * entities, no network (`LIBXML_NONET`).
 *
 * Bytes are decoded to UTF-8 first: the transport charset wins, then a BOM,
 * then a `<meta charset>` in the first 4 KiB, then UTF-8. Broken markup is
 * tolerated; libxml's parse errors are swallowed without touching the caller's
 * `libxml_use_internal_errors()` setting.
 *
 * @api
 */
final readonly class PageDocument
{
    private const int SNIFF_BYTES = 4096;

    private function __construct(
        private DOMDocument $document,
        private DOMXPath $xpath,
    ) {}

    /**
     * @param string|null $charset charset announced by the transport (`Content-Type`)
     */
    public static function parse(string $html, ?string $charset = null): self
    {
        $document = new DOMDocument();
        $utf8 = str_replace("\0", '', self::toUtf8($html, $charset));

        if (trim($utf8) !== '') {
            $previous = libxml_use_internal_errors(true);

            try {
                $document->loadHTML('<?xml encoding="UTF-8"?>' . $utf8, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            } finally {
                // switching the handling off also empties libxml's error buffer
                libxml_use_internal_errors($previous);
            }
        }

        return new self($document, new DOMXPath($document));
    }

    public function title(): ?string
    {
        foreach ($this->elements('//title') as $title) {
            $text = trim($title->textContent);

            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * The `lang` of the root element.
     */
    public function language(): ?string
    {
        foreach ($this->elements('/html') as $root) {
            $lang = trim($root->getAttribute('lang'));

            return $lang === '' ? null : $lang;
        }

        return null;
    }

    /**
     * The `href` of the first `<base>`, as written.
     */
    public function baseHref(): ?string
    {
        foreach ($this->elements('//base[@href]') as $base) {
            $href = trim($base->getAttribute('href'));

            if ($href !== '') {
                return $href;
            }
        }

        return null;
    }

    /**
     * Non-empty `content` of every `<meta>` whose `property` or `name` equals the key,
     * case-insensitively, in document order.
     *
     * @return list<string>
     */
    public function meta(string $key): array
    {
        $key = strtolower($key);
        $values = [];

        foreach ($this->elements('//meta[@content]') as $meta) {
            if (strtolower(trim($meta->getAttribute('property'))) !== $key && strtolower(trim($meta->getAttribute('name'))) !== $key) {
                continue;
            }

            $content = trim($meta->getAttribute('content'));

            if ($content !== '') {
                $values[] = $content;
            }
        }

        return $values;
    }

    /**
     * Raw text of every `<script type="...">` of the given type, as written.
     * It is data to be parsed by the caller, never executed.
     *
     * @return list<string>
     */
    public function scripts(string $type): array
    {
        $type = strtolower($type);
        $bodies = [];

        foreach ($this->elements('//script[@type]') as $script) {
            if (strtolower(trim($script->getAttribute('type'))) === $type && trim($script->textContent) !== '') {
                $bodies[] = $script->textContent;
            }
        }

        return $bodies;
    }

    /**
     * `href` of every `<link rel="alternate" type="...">` of the given type.
     *
     * @return list<string>
     */
    public function alternates(string $type): array
    {
        $type = strtolower($type);
        $hrefs = [];

        foreach ($this->elements('//link[@href][@rel][@type]') as $link) {
            $tokens = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel'))), -1, PREG_SPLIT_NO_EMPTY);
            $href = trim($link->getAttribute('href'));

            if (
                $href !== ''
                && strtolower(trim($link->getAttribute('type'))) === $type
                && \in_array('alternate', $tokens === false ? [] : $tokens, true)
            ) {
                $hrefs[] = $href;
            }
        }

        return $hrefs;
    }

    /**
     * Non-empty `href` of every `<link>` whose `rel` contains the token, in document order.
     *
     * @return list<string>
     */
    public function links(string $rel): array
    {
        $rel = strtolower($rel);
        $hrefs = [];

        foreach ($this->elements('//link[@href][@rel]') as $link) {
            $tokens = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel'))), -1, PREG_SPLIT_NO_EMPTY);
            $href = trim($link->getAttribute('href'));

            if ($href !== '' && \in_array($rel, $tokens === false ? [] : $tokens, true)) {
                $hrefs[] = $href;
            }
        }

        return $hrefs;
    }

    /**
     * Every `<img src>` with its declared dimensions, in document order.
     *
     * @return list<array{src: string, width: int<0, max>|null, height: int<0, max>|null}>
     */
    public function images(): array
    {
        $images = [];

        foreach ($this->elements('//img[@src]') as $image) {
            $src = trim($image->getAttribute('src'));

            if ($src === '') {
                continue;
            }

            $images[] = [
                'src' => $src,
                'width' => self::dimension($image->getAttribute('width')),
                'height' => self::dimension($image->getAttribute('height')),
            ];
        }

        return $images;
    }

    /**
     * @return list<DOMElement>
     */
    private function elements(string $query): array
    {
        $nodes = $this->xpath->query($query);
        $elements = [];

        if ($nodes === false) {
            return $elements;
        }

        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * @return int<0, max>|null
     */
    private static function dimension(string $value): ?int
    {
        return preg_match('/\A\s*(\d{1,6})(?:px)?\s*\z/', $value, $matches) === 1 ? max(0, (int) $matches[1]) : null;
    }

    private static function toUtf8(string $html, ?string $transportCharset): string
    {
        foreach (["\xEF\xBB\xBF" => 'UTF-8', "\xFF\xFE" => 'UTF-16LE', "\xFE\xFF" => 'UTF-16BE'] as $bom => $encoding) {
            if (str_starts_with($html, $bom)) {
                return self::convert(substr($html, \strlen($bom)), $encoding);
            }
        }

        $charset = $transportCharset;

        if ($charset === null || trim($charset) === '') {
            $head = substr($html, 0, self::SNIFF_BYTES);
            $charset = preg_match('/<meta\s[^>]*?charset\s*=\s*["\']?\s*([A-Za-z0-9._:-]+)/i', $head, $matches) === 1
                ? $matches[1]
                : 'UTF-8';
        }

        return self::convert($html, $charset);
    }

    private static function convert(string $html, string $charset): string
    {
        $charset = match (strtolower(trim($charset))) {
            'iso-8859-1', 'latin1', 'us-ascii', 'ascii' => 'Windows-1252',
            default => trim($charset),
        };

        try {
            return (string) mb_convert_encoding($html, 'UTF-8', $charset);
        } catch (ValueError) {
            return (string) mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        }
    }
}
