<?php

declare(strict_types=1);

namespace viesrood\seokit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\seokit\helpers\AiCrawlers;

/**
 * The exact bytes that end up in robots.txt.
 *
 * Worth asserting rather than eyeballing, because the failure mode is silent:
 * robots.txt group matching is most-specific-wins, not cumulative, so a stray
 * `Allow: /` under a named user agent would quietly cancel every rule the `*`
 * group has for that bot. Nothing about the file looks wrong when that happens.
 */
final class AiCrawlersTest extends TestCase
{
    public function testEverythingOffAndNoSignalWritesNothing(): void
    {
        self::assertSame('', AiCrawlers::block(false, false, ''));
    }

    public function testADisabledGroupWritesNoRulesAtAll(): void
    {
        $block = AiCrawlers::block(blockTraining: false, blockSearch: true, contentSignal: '');

        self::assertStringNotContainsString('GPTBot', $block);
        self::assertStringContainsString("User-agent: PerplexityBot\n", $block);

        // Not "Allow: /" for the group that stays welcome: an explicit group
        // for a bot replaces the * group for that bot entirely.
        self::assertStringNotContainsString('Allow:', $block);
    }

    public function testTrainingGroupIsOneGroupWithASingleDisallow(): void
    {
        $block = AiCrawlers::block(true, false, '');

        foreach (AiCrawlers::TRAINING as $agent) {
            self::assertStringContainsString('User-agent: ' . $agent . "\n", $block);
        }

        self::assertSame(1, substr_count($block, 'Disallow: /'));
    }

    public function testBothGroupsEachGetTheirOwnDisallow(): void
    {
        $block = AiCrawlers::block(true, true, '');

        self::assertSame(2, substr_count($block, 'Disallow: /'));
    }

    public function testContentSignalIsWrittenUnderTheWildcardGroup(): void
    {
        $block = AiCrawlers::block(false, false, 'search=yes, ai-train=no');

        self::assertStringContainsString("User-agent: *\nContent-Signal: search=yes, ai-train=no", $block);
    }

    public function testContentSignalIsFlattenedToOneLine(): void
    {
        // A newline here would let this setting write its own directives.
        $block = AiCrawlers::block(false, false, "search=yes\nDisallow: /");

        self::assertStringContainsString('Content-Signal: search=yes Disallow: /', $block);

        // The injected text stays inside the value; it never becomes a rule of
        // its own, which is the whole point of flattening it.
        self::assertSame(0, substr_count($block, "\nDisallow: /"));
    }

    public function testAnLlmsUrlBecomesACommentAndNotADirective(): void
    {
        $block = AiCrawlers::block(false, false, '', 'https://example.com/llms.txt');

        self::assertStringContainsString('# llms.txt: https://example.com/llms.txt', $block);
        self::assertStringNotContainsString("\nLlms:", $block);
    }

    public function testBlockingSearchWhileClaimingSearchIsYesIsReported(): void
    {
        $found = AiCrawlers::contradictions(false, true, 'search=yes, ai-input=yes, ai-train=no');

        self::assertSame(['search=yes', 'ai-input=yes'], $found);
    }

    public function testBlockingTrainingWhileClaimingAiTrainIsYesIsReported(): void
    {
        self::assertSame(['ai-train=yes'], AiCrawlers::contradictions(true, false, 'ai-train=yes'));
    }

    public function testTheDefaultSignalContradictsNothingByDefault(): void
    {
        self::assertSame([], AiCrawlers::contradictions(false, false, 'search=yes, ai-input=yes, ai-train=no'));
        self::assertSame([], AiCrawlers::contradictions(true, false, 'search=yes, ai-input=yes, ai-train=no'));
    }
}
