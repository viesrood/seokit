<?php

declare(strict_types=1);

namespace viesrood\seokit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\seokit\helpers\Text;

final class TextTest extends TestCase
{
    public function testBlockTagsBecomeSpaces(): void
    {
        // Without this, "</p><p>" glues the last word of one paragraph to the
        // first word of the next and the description reads as gibberish.
        self::assertSame(
            'Eerste alinea. Tweede alinea.',
            Text::plain('<p>Eerste alinea.</p><p>Tweede alinea.</p>'),
        );
    }

    public function testLineBreaksAndListItemsSeparateWords(): void
    {
        self::assertSame('een twee drie', Text::plain('een<br>twee<li>drie</li>'));
    }

    public function testNonBreakingSpacesCollapse(): void
    {
        // A non-breaking space survives html_entity_decode and then defeats
        // every whitespace collapse that only knows about \s.
        self::assertSame('een twee', Text::plain('een&nbsp;&nbsp;twee'));
    }

    public function testEntitiesAreDecoded(): void
    {
        self::assertSame('kosten & baten "hier"', Text::plain('kosten &amp; baten &quot;hier&quot;'));
    }

    public function testEmptyInputStaysEmpty(): void
    {
        self::assertSame('', Text::plain(''));
    }

    public function testTruncateLeavesShortTextAlone(): void
    {
        self::assertSame('kort', Text::truncate('kort', 100));
    }

    public function testTruncateCutsOnAWordBoundary(): void
    {
        $result = Text::truncate('een twee drie vier vijf zes', 16);

        self::assertSame('een twee drie', $result);
        self::assertStringEndsNotWith('…', $result);
    }

    public function testTruncateNeverAppendsAnEllipsis(): void
    {
        // Search engines add their own when they truncate. One already in the
        // tag just looks broken.
        self::assertStringNotContainsString('…', Text::truncate(str_repeat('woord ', 40), 50));
    }

    public function testSummarizeEndsOnASentenceWhenOneFits(): void
    {
        $tekst = 'Dit is de eerste zin. En dit is een tweede zin die er ruim overheen gaat.';

        self::assertSame('Dit is de eerste zin.', Text::summarize($tekst, 40));
    }

    public function testSummarizeFallsBackToAWordBoundaryWhenTheSentenceEndsTooEarly(): void
    {
        // The full stop sits at character 3, well under half the budget, so
        // honouring it would throw away almost everything.
        $tekst = 'Ja. Daarna volgt een veel langere passage die het budget ruim overschrijdt.';
        $result = Text::summarize($tekst, 40);

        self::assertNotSame('Ja.', $result);
        self::assertLessThanOrEqual(40, mb_strlen($result));
    }

    public function testSummarizeLeavesTextThatAlreadyFits(): void
    {
        self::assertSame('Past prima.', Text::summarize('Past prima.', 100));
    }

    public function testSummarizeHandlesQuestionAndExclamationMarks(): void
    {
        $tekst = 'Waarom kiezen we voor Craft? Omdat het beter past bij ons werk dan de alternatieven.';

        self::assertSame('Waarom kiezen we voor Craft?', Text::summarize($tekst, 45));
    }
}
