<?php

declare(strict_types=1);

namespace viesrood\seokit\helpers;

/**
 * The shape of llms.txt, separated from the work of finding out what goes in it.
 *
 * Pure, so the exact file can be asserted in a unit test the way the robots.txt
 * block can. LlmsService queries Craft; this decides what the result looks like.
 *
 * The format is llmstxt.org: an H1, an optional blockquote summary, free text,
 * then H2 sections of `- [title](url): description` links. Only the H1 is
 * required, and everything else disappears when empty rather than leaving an
 * empty heading for a reader to wonder about.
 */
final class LlmsDocument
{
    /**
     * @param string[] $blocks Rendered sections, in order.
     */
    public static function assemble(string $siteName, string $summary, string $intro, array $blocks): string
    {
        $parts = ['# ' . trim($siteName)];

        if (trim($summary) !== '') {
            $parts[] = '> ' . trim($summary);
        }

        if (trim($intro) !== '') {
            $parts[] = trim($intro);
        }

        foreach ($blocks as $block) {
            if (trim($block) !== '') {
                $parts[] = trim($block);
            }
        }

        return implode("\n\n", $parts) . "\n";
    }

    /**
     * One page as a link line.
     */
    public static function line(string $title, string $url, string $description = ''): string
    {
        $line = sprintf('- [%s](%s)', self::escape(trim($title)), trim($url));
        $description = trim($description);

        return $description === '' ? $line : $line . ': ' . $description;
    }

    /**
     * One section, or an empty string when it has no pages.
     *
     * `$truncated` makes the cut visible. Silent truncation is how a reader
     * ends up certain that a site has exactly 200 pages, and the cut is the
     * entire reason this file exists next to a sitemap that has no caps.
     *
     * No count in that line on purpose: knowing the exact number of pages left
     * out would mean loading a whole section to count what is deliberately not
     * being read. The sitemap it points at has all of them anyway.
     *
     * @param string[] $lines
     */
    public static function section(string $name, array $lines, bool $truncated = false, string $moreUrl = ''): string
    {
        if ($lines === []) {
            return '';
        }

        if ($truncated && $moreUrl !== '') {
            $lines[] = sprintf('- ...and more, see %s', $moreUrl);
        }

        return '## ' . trim($name) . "\n\n" . implode("\n", $lines);
    }

    /**
     * The Optional section: the one an agent may skip when its context is
     * tight, which is exactly where hand-added links belong.
     *
     * @param string[] $links One per line, as `url: label`.
     */
    public static function optional(array $links): string
    {
        $lines = [];

        foreach ($links as $link) {
            [$url, $label] = array_pad(array_map('trim', explode(': ', trim($link), 2)), 2, '');

            if ($url === '') {
                continue;
            }

            $lines[] = self::line($label === '' ? $url : $label, $url);
        }

        return $lines === [] ? '' : "## Optional\n\n" . implode("\n", $lines);
    }

    /**
     * A bracket in a title would end the link text early and leave the rest of
     * the line as loose prose.
     */
    private static function escape(string $text): string
    {
        return str_replace(['[', ']'], ['\[', '\]'], $text);
    }
}
