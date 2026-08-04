<?php

declare(strict_types=1);

namespace viesrood\seokit\helpers;

/**
 * Turning page content into one line of readable text.
 */
final class Text
{
    /**
     * Strip markup and collapse whitespace.
     *
     * Block-level tags become a space first, otherwise "</p><p>" glues two
     * sentences into one word and the description reads like a ransom note.
     */
    public static function plain(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // Opening tags as well as closing ones. Only closing tags separates
        // "twee<li>drie</li>" into "tweedrie": the text before a list runs
        // straight into its first item.
        $text = preg_replace(
            '/<\/?(br|p|h[1-6]|li|div|td|tr|section|article|blockquote)[^>]*>/i',
            ' ',
            $html,
        ) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Non-breaking spaces survive the decode and then defeat every
        // whitespace collapse that only knows about \s.
        $text = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Cut to length on a word boundary.
     *
     * No trailing ellipsis: search engines add their own when they truncate,
     * and a description that already ends in one just looks broken.
     */
    public static function truncate(string $text, int $length): string
    {
        if ($length < 1 || mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > (int)($length * 0.5)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r\0\x0B,;:.-");
    }

    /**
     * Trim a sentence-aware summary: whole sentences when they fit the budget,
     * otherwise a clean word-boundary cut.
     *
     * A description that ends mid-clause reads as an accident. One that ends
     * on a full stop reads as a decision, and it is the same text either way.
     */
    public static function summarize(string $text, int $length): string
    {
        $text = trim($text);

        if ($text === '' || mb_strlen($text) <= $length) {
            return $text;
        }

        $window = mb_substr($text, 0, $length + 1);

        // Look for the last sentence end, but only accept it if it leaves a
        // description worth having. Half a budget spent is the cutoff.
        if (preg_match_all('/[.!?](?=\s|$)/u', $window, $matches, PREG_OFFSET_CAPTURE)) {
            $last = end($matches[0]);
            $byteOffset = (int)$last[1];
            $charOffset = mb_strlen(substr($window, 0, $byteOffset));

            // Half the budget counts as enough: a sentence that ends exactly
            // there is still a whole sentence, and a whole sentence beats a
            // longer fragment.
            if ($charOffset >= (int)($length * 0.5)) {
                return trim(mb_substr($text, 0, $charOffset + 1));
            }
        }

        return self::truncate($text, $length);
    }
}
