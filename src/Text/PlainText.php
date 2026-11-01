<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Text;

/**
 * Turns what a page calls text into plain text: invalid UTF-8 scrubbed, HTML
 * entities decoded (double escaping included), markup removed, control
 * characters and runs of whitespace collapsed to one space.
 *
 * Decoding can produce markup (`&lt;b&gt;`) and markup can hide entities, so
 * decode, strip and collapse repeat until nothing changes, at most
 * {@see self::MAX_ROUNDS} times. Text still changing after that is escaped
 * deeper than any real page does; its remaining `&` and `<` become their
 * full-width forms (`＆`, `＜`), so the result is final either way and
 * cleaning it again changes nothing.
 *
 * A tag is replaced by a space, never by nothing: removing it must not glue two
 * halves of an entity (`&am<b>p;`) or two words together. A `<` that opens no
 * tag (`a < b`, `<3`) is text. Every pattern is linear: a tag cannot contain
 * another `<`, so hostile input cannot make the cleaning quadratic.
 *
 * @internal
 */
final readonly class PlainText
{
    public const int MAX_ROUNDS = 4;

    /** a tag, a closing tag, a comment, a doctype or a processing instruction; none may span another `<` */
    private const string MARKUP = '~<(?:/?[A-Za-z]|[!?])[^<>]*>~';

    private function __construct() {}

    /**
     * @return non-empty-string|null `null` when nothing but markup and whitespace was there
     */
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = mb_scrub($value, 'UTF-8');

        for ($round = 0; $round < self::MAX_ROUNDS; ++$round) {
            $next = self::round($text);

            if ($next === $text) {
                return $text === '' ? null : $text;
            }

            $text = $next;
        }

        $text = self::round($text) === $text ? $text : str_replace(['&', '<'], ['＆', '＜'], $text);

        return $text === '' ? null : $text;
    }

    private static function round(string $text): string
    {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = preg_replace(self::MARKUP, ' ', $decoded) ?? $decoded;

        return trim(preg_replace('/[\s\p{Cc}]+/u', ' ', $stripped) ?? $stripped);
    }
}
