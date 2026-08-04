<?php

declare(strict_types=1);

namespace viesrood\seokit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\seokit\helpers\LlmsDocument;

/**
 * The llmstxt.org shape, asserted rather than eyeballed.
 *
 * Two things matter here and neither is visible by reading the output once. The
 * heading is the only required part, so everything else has to vanish cleanly
 * when it is empty instead of leaving a bare `>` or an H2 with nothing under
 * it. And truncation has to be stated in the file, because a list that stops
 * without saying so reads as a complete list.
 */
final class LlmsDocumentTest extends TestCase
{
    public function testTheHeadingIsEnoughForAValidDocument(): void
    {
        self::assertSame("# Viesrood\n", LlmsDocument::assemble('Viesrood', '', '', []));
    }

    public function testTheSummaryBecomesABlockquoteAndOnlyWhenThereIsOne(): void
    {
        $with = LlmsDocument::assemble('Viesrood', 'Bureau voor merken.', '', []);
        $without = LlmsDocument::assemble('Viesrood', '   ', '', []);

        self::assertStringContainsString("\n\n> Bureau voor merken.\n", $with);
        self::assertStringNotContainsString('>', $without);
    }

    public function testEmptyBlocksLeaveNoBlankSections(): void
    {
        $document = LlmsDocument::assemble('Viesrood', '', '', ['', '  ', '## Werk']);

        self::assertSame("# Viesrood\n\n## Werk\n", $document);
    }

    public function testALinkLineCarriesTitleUrlAndDescription(): void
    {
        self::assertSame(
            '- [Nieuwe Energie](https://example.com/werk/neo): Een merk voor de energietransitie.',
            LlmsDocument::line('Nieuwe Energie', 'https://example.com/werk/neo', 'Een merk voor de energietransitie.'),
        );
    }

    public function testALinkLineWithoutADescriptionHasNoTrailingColon(): void
    {
        self::assertSame('- [Werk](https://example.com/werk)', LlmsDocument::line('Werk', 'https://example.com/werk', ' '));
    }

    public function testBracketsInATitleAreEscaped(): void
    {
        // Unescaped, the link text would end at the first bracket and the rest
        // of the title would sit on the line as loose prose.
        $line = LlmsDocument::line('Case [2026]', 'https://example.com/a');

        self::assertSame('- [Case \[2026\]](https://example.com/a)', $line);
    }

    public function testASectionWithoutPagesIsNotWritten(): void
    {
        self::assertSame('', LlmsDocument::section('Werk', []));
    }

    public function testTruncationIsStatedInTheFile(): void
    {
        $section = LlmsDocument::section(
            'Artikelen',
            ['- [Een](https://example.com/een)'],
            true,
            'https://example.com/sitemap-artikelen.xml',
        );

        self::assertStringEndsWith('- ...and more, see https://example.com/sitemap-artikelen.xml', $section);
    }

    public function testAnUntruncatedSectionSaysNothingAboutMore(): void
    {
        $section = LlmsDocument::section('Artikelen', ['- [Een](https://example.com/een)'], false, 'https://example.com/x.xml');

        self::assertStringNotContainsString('and more', $section);
    }

    public function testOptionalLinksSplitOnTheFirstColonSpace(): void
    {
        $optional = LlmsDocument::optional([
            'https://example.com/handboek: Personeelshandboek',
            '  ',
            'https://example.com/kaal',
        ]);

        self::assertSame(implode("\n", [
            '## Optional',
            '',
            '- [Personeelshandboek](https://example.com/handboek)',
            '- [https://example.com/kaal](https://example.com/kaal)',
        ]), $optional);
    }

    public function testNoOptionalSectionWithoutLinks(): void
    {
        self::assertSame('', LlmsDocument::optional(['', '   ']));
    }
}
