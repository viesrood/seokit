<?php

declare(strict_types=1);

namespace viesrood\seokit\helpers;

/**
 * Deciding whether a caller actually asked for markdown.
 *
 * This is a short question with a one-line wrong answer that half the
 * implementations in the wild ship: `str_contains($header, 'text/markdown')`.
 * A browser sends a header ending in a wildcard with a lowered quality, and the
 * moment anything on the site advertises a markdown alternative, that substring
 * test starts handing raw markdown to people with a screen. No error, no log
 * line, just a page of asterisks.
 *
 * So the rule is strict: markdown wins only when it was named explicitly and
 * its quality is strictly higher than the best claim HTML can make, wildcards
 * included. A tie goes to HTML, because a client that values both equally is a
 * client that renders HTML.
 *
 * No parser here on purpose. yii\web\Request::getAcceptableContentTypes() has
 * done RFC 7231 for years, including the sort order. Reusing it keeps this
 * helper pure and saves keeping a second parser correct.
 */
final class Accept
{
    /**
     * Types that mean markdown but never outrank an explicit text/html.
     *
     * `text/x-markdown` is the deprecated spelling and `application/markdown`
     * was never registered. Honouring them when nothing else was asked for is
     * friendly; letting them beat a real text/html is not.
     */
    private const WEAK = ['text/x-markdown', 'application/markdown'];

    /**
     * Types that let HTML win, from most to least specific. The wildcard has to
     * count: it says "anything, and I mean it", and a browser sending it will
     * cheerfully render whatever comes back as text.
     */
    private const HTML = ['text/html', 'text/*', '*/*'];

    /**
     * @param array<string, mixed> $acceptable As returned by
     *     yii\web\Request::getAcceptableContentTypes(): content type => params,
     *     where params may carry a 'q'.
     */
    public static function prefers(array $acceptable, string $type = 'text/markdown'): bool
    {
        $normalized = self::normalize($acceptable);
        $wanted = $normalized[strtolower(trim($type))] ?? null;
        $html = self::best($normalized, self::HTML);

        if ($wanted !== null) {
            return $wanted > $html;
        }

        // A weak spelling only wins when HTML was not asked for at all, which
        // in practice means a script rather than a browser.
        return $html <= 0.0 && self::best($normalized, self::WEAK) > 0.0;
    }

    /**
     * The strongest quality among a set of types, or 0.0 when none was asked
     * for.
     *
     * @param array<string, float> $normalized
     * @param string[] $types
     */
    private static function best(array $normalized, array $types): float
    {
        $best = 0.0;

        foreach ($types as $type) {
            $quality = $normalized[$type] ?? null;

            if ($quality !== null && $quality > $best) {
                $best = $quality;
            }
        }

        return $best;
    }

    /**
     * Lowercased type => quality, with a missing q meaning 1.0 as the spec says.
     *
     * @param array<string, mixed> $acceptable
     * @return array<string, float>
     */
    private static function normalize(array $acceptable): array
    {
        $normalized = [];

        foreach ($acceptable as $type => $params) {
            $quality = 1.0;

            if (is_array($params) && isset($params['q']) && is_numeric($params['q'])) {
                $quality = (float)$params['q'];
            }

            $normalized[strtolower(trim((string)$type))] = $quality;
        }

        return $normalized;
    }
}
