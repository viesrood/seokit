<?php

declare(strict_types=1);

namespace viesrood\seokit\helpers;

/**
 * The robots.txt block that speaks to AI crawlers.
 *
 * robots.txt is the only signal AI crawlers demonstrably respect, and the
 * distinction that matters is not "AI yes or no" but *which* AI. A bot that
 * collects pages to train a model gives nothing back; a bot that fetches a page
 * to cite it in an answer is a referral. Those are two decisions, so they are
 * two switches, and the user agent lists behind them live here rather than in a
 * setting: a list somebody has to maintain by hand is out of date within a year.
 *
 * Pure on purpose. No Craft, no settings model, no I/O, so the exact bytes that
 * end up in robots.txt can be asserted in a unit test.
 */
final class AiCrawlers
{
    /**
     * Crawlers that collect pages as training material.
     *
     * Two of these are not crawlers at all. `Google-Extended` and
     * `Applebot-Extended` are opt-out tokens: no request ever carries them, and
     * disallowing them tells Gemini and Apple Intelligence not to train on the
     * site without touching Googlebot or Applebot. Listing them next to real
     * user agents is what the specs of both vendors ask for.
     *
     * @var string[]
     */
    public const TRAINING = [
        'GPTBot',
        'ClaudeBot',
        'anthropic-ai',
        'Google-Extended',
        'Applebot-Extended',
        'meta-externalagent',
        'Amazonbot',
        'Bytespider',
        'CCBot',
    ];

    /**
     * Crawlers that fetch a page to answer a question and link back to it.
     *
     * Blocking this group takes the site out of AI answers entirely. That is a
     * real choice, not a privacy setting, which is why it is separate from the
     * one above and why the control panel says so out loud.
     *
     * @var string[]
     */
    public const SEARCH = [
        'OAI-SearchBot',
        'ChatGPT-User',
        'Claude-SearchBot',
        'Claude-User',
        'PerplexityBot',
        'Perplexity-User',
    ];

    /**
     * The block to drop into robots.txt, or an empty string when there is
     * nothing to say.
     *
     * @param string $contentSignal Content-Signal directives, or '' to omit them.
     * @param string $llmsUrl Absolute URL of llms.txt, or '' when none is served.
     */
    public static function block(
        bool $blockTraining,
        bool $blockSearch,
        string $contentSignal = '',
        string $llmsUrl = '',
    ): string {
        $groups = [];
        $signal = self::normalizeSignal($contentSignal);

        if ($signal !== '') {
            $groups[] = implode("\n", [
                '# What this site allows, for the crawlers that read it. A statement of',
                '# intent: the rules below are what actually enforces anything.',
                'User-agent: *',
                'Content-Signal: ' . $signal,
            ]);
        }

        if ($blockTraining) {
            $groups[] = implode("\n", [
                '# No training on this content. Costs nothing in search results:',
                '# Google-Extended and Applebot-Extended are opt-out tokens, not',
                '# crawlers, so Googlebot and Applebot are unaffected.',
                ...array_map(static fn(string $agent): string => 'User-agent: ' . $agent, self::TRAINING),
                'Disallow: /',
            ]);
        }

        if ($blockSearch) {
            $groups[] = implode("\n", [
                '# No AI answer engines either, which also means no citations from them.',
                ...array_map(static fn(string $agent): string => 'User-agent: ' . $agent, self::SEARCH),
                'Disallow: /',
            ]);
        }

        // A comment, not a directive. `Sitemap:` is standardised and `Llms:` is
        // not, and an invented directive is noise in every parser that reads
        // this file.
        if ($llmsUrl !== '') {
            $groups[] = '# llms.txt: ' . $llmsUrl;
        }

        if ($groups === []) {
            return '';
        }

        return implode("\n\n", $groups) . "\n";
    }

    /**
     * Content-Signal directives that claim the opposite of what the switches do.
     *
     * Worth catching, because the file would then say one thing in prose and
     * another in rules, and only the rules are read by anything that enforces.
     *
     * @return string[] The offending directives, as written.
     */
    public static function contradictions(bool $blockTraining, bool $blockSearch, string $contentSignal): array
    {
        $signal = self::normalizeSignal($contentSignal);

        if ($signal === '') {
            return [];
        }

        $found = [];

        foreach (explode(',', $signal) as $directive) {
            $directive = trim($directive);
            [$name, $value] = array_pad(array_map('trim', explode('=', $directive, 2)), 2, '');

            if ($value !== 'yes') {
                continue;
            }

            if ($blockSearch && ($name === 'search' || $name === 'ai-input')) {
                $found[] = $directive;
            }

            if ($blockTraining && $name === 'ai-train') {
                $found[] = $directive;
            }
        }

        return $found;
    }

    /**
     * One line, always. A newline in this value would let a stray setting write
     * its own robots.txt directives.
     */
    private static function normalizeSignal(string $contentSignal): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $contentSignal));
    }
}
