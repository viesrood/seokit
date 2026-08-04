<?php

declare(strict_types=1);

namespace viesrood\seokit\helpers;

use League\HTMLToMarkdown\HtmlConverter;

/**
 * HTML to markdown, plus the four things a general converter cannot know.
 *
 * The conversion itself is league/html-to-markdown, SeoKit's only runtime
 * dependency besides Craft. That is a deliberate trade: the alternative was a
 * hand-written DOM walker that has to stay correct for headings, nested lists,
 * tables and malformed markup forever, which is a larger maintenance burden
 * than one small MIT library.
 *
 * What is added here is context the library has no way to have:
 *
 * - **Heading demotion.** The page title is the document's only `#`. An `<h1>`
 *   in the body becomes `##` and everything under it shifts along, so the
 *   outline of the file matches the outline of the page.
 * - **Absolute URLs.** A markdown file is read away from the page it came from,
 *   where a root-relative href resolves against nothing.
 * - **Task lists.** CKEditor's `todoList` renders as a checkbox input inside a
 *   label, which a generic converter drops on the floor, leaving list items
 *   that all look identical whether they are done or not.
 * - **Embeds.** An `<iframe>` on these pages is content, not decoration. Losing
 *   it silently turns a page that is mostly video into a page that is mostly
 *   nothing.
 */
final class Markdown
{
    /**
     * Placeholders for the checkbox state, carried through the converter as
     * plain letters.
     *
     * They have to survive TextConverter, which escapes `*`, `_`, `[`, `]` and
     * a leading `#`. Writing `[x]` here would come out as `\[x\]`, so the
     * brackets go on afterwards.
     */
    private const TODO_DONE = 'SEOKITTASKDONE';

    private const TODO_OPEN = 'SEOKITTASKOPEN';

    /**
     * @param string $baseUrl Absolute site root, used to resolve relative
     *     links. Empty leaves them as they are, which is what a unit test
     *     wants and what a misconfigured site gets instead of an exception.
     */
    public static function fromHtml(string $html, string $baseUrl = ''): string
    {
        if (trim($html) === '') {
            return '';
        }

        $converter = new HtmlConverter([
            // Setext underlines carry no level past h2 and read badly when the
            // whole document is being demoted one step.
            'header_style' => 'atx',
            'strip_tags' => true,
            'remove_nodes' => 'script style noscript',
            'hard_break' => true,
        ]);

        $markdown = $converter->convert(self::prepare($html, $baseUrl));

        return trim(self::finish($markdown));
    }

    /**
     * Escape text that never went through HTML: a plain text field, a title.
     *
     * Without this a product code like `pak_2*3` turns half a paragraph
     * italic, and a line starting with a number and a dot becomes a numbered
     * list that was never in the source.
     */
    public static function escape(string $text): string
    {
        $escaped = str_replace(
            ['\\', '`', '*', '_', '[', ']'],
            ['\\\\', '\\`', '\\*', '\\_', '\\[', '\\]'],
            $text,
        );

        // Only at the start of a line, where they are structure rather than
        // punctuation.
        $escaped = (string)preg_replace('/^(\s*)([#>-])/m', '$1\\\\$2', $escaped);

        return (string)preg_replace('/^(\s*)(\d+)\./m', '$1$2\\.', $escaped);
    }

    /**
     * Rewrites in the HTML, before the converter sees it.
     */
    private static function prepare(string $html, string $baseUrl): string
    {
        // An iframe is a void-ish element for our purposes: keep the source,
        // drop the player.
        $html = (string)preg_replace_callback(
            '#<iframe\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>.*?</iframe>#is',
            static fn(array $m): string => sprintf('<p><a href="%s">embed</a></p>', $m[1]),
            $html,
        );

        $html = (string)preg_replace_callback(
            '#<input\b[^>]*type=["\']checkbox["\'][^>]*>#i',
            static fn(array $m): string => str_contains(strtolower($m[0]), 'checked')
                ? self::TODO_DONE . ' '
                : self::TODO_OPEN . ' ',
            $html,
        );

        if ($baseUrl !== '') {
            $html = (string)preg_replace_callback(
                '/\b(href|src)=(["\'])([^"\']*)\2/i',
                static fn(array $m): string => sprintf('%s="%s"', $m[1], self::absolute($m[3], $baseUrl)),
                $html,
            );
        }

        return $html;
    }

    /**
     * Resolve one URL against the site root.
     *
     * Deliberately not the Url helper: that reaches into Craft for the base,
     * and this class stays testable without a bootstrapped application. The
     * caller knows the site root; here it is only string work.
     */
    private static function absolute(string $url, string $baseUrl): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        // Already absolute, protocol-relative, or a scheme of its own such as
        // mailto: or tel:, none of which should be mangled into a path.
        if (str_starts_with($url, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            return $url;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Rewrites in the markdown, after the converter is done.
     */
    private static function finish(string $markdown): string
    {
        // Demote every heading by one, leaving h6 where it is: markdown has no
        // seventh level, and losing the heading entirely would be worse than
        // two levels sharing a depth.
        $markdown = (string)preg_replace('/^(#{1,5})(?= )/m', '$1#', $markdown);

        $markdown = str_replace(
            [self::TODO_DONE . ' ', self::TODO_OPEN . ' '],
            ['[x] ', '[ ] '],
            $markdown,
        );

        // Three or more blank lines happen wherever a wrapper element was
        // stripped, and read as if something is missing.
        return (string)preg_replace("/\n{3,}/", "\n\n", $markdown);
    }
}
