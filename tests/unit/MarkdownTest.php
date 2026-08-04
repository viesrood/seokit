<?php

declare(strict_types=1);

namespace viesrood\seokit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\seokit\helpers\Markdown;

/**
 * The four things a general HTML-to-markdown converter cannot know.
 *
 * The library underneath is well tested and not retested here. What is tested
 * is everything SeoKit adds on top, plus the promise that malformed markup
 * comes back as text rather than as an exception: this runs on a page request,
 * and half a document beats a 500.
 */
final class MarkdownTest extends TestCase
{
    public function testHeadingsAreDemotedSoTheTitleKeepsTheOnlyH1(): void
    {
        $markdown = Markdown::fromHtml('<h1>Werkwijze</h1><h2>Fase een</h2>');

        self::assertStringContainsString('## Werkwijze', $markdown);
        self::assertStringContainsString('### Fase een', $markdown);
        self::assertStringNotContainsString("\n# ", "\n" . $markdown);
    }

    public function testTheSixthLevelStaysWhereItIs(): void
    {
        // Markdown has no seventh level, and dropping the heading would be
        // worse than two levels sharing a depth.
        self::assertStringContainsString('###### Diep', Markdown::fromHtml('<h6>Diep</h6>'));
    }

    public function testRelativeLinksBecomeAbsolute(): void
    {
        $markdown = Markdown::fromHtml('<p><a href="/werk/neo">Neo</a></p>', 'https://viesrood.nl');

        self::assertStringContainsString('[Neo](https://viesrood.nl/werk/neo)', $markdown);
    }

    public function testImagesAreResolvedToo(): void
    {
        $markdown = Markdown::fromHtml('<p><img src="/uploads/a.jpg" alt="Een"></p>', 'https://viesrood.nl');

        self::assertStringContainsString('(https://viesrood.nl/uploads/a.jpg)', $markdown);
    }

    public function testAbsoluteAndSchemeUrlsAreLeftAlone(): void
    {
        $html = '<p><a href="https://example.com/a">A</a> <a href="mailto:hoi@viesrood.nl">Mail</a></p>';
        $markdown = Markdown::fromHtml($html, 'https://viesrood.nl');

        self::assertStringContainsString('(https://example.com/a)', $markdown);
        self::assertStringContainsString('(mailto:hoi@viesrood.nl)', $markdown);
    }

    public function testWithoutABaseUrlLinksAreUntouched(): void
    {
        self::assertStringContainsString('(/werk)', Markdown::fromHtml('<p><a href="/werk">W</a></p>'));
    }

    public function testCkeditorTaskListsKeepTheirCheckedState(): void
    {
        $html = '<ul class="todo-list">'
            . '<li><label class="todo-list__label"><input type="checkbox" disabled checked><span>Klaar</span></label></li>'
            . '<li><label class="todo-list__label"><input type="checkbox" disabled><span>Nog niet</span></label></li>'
            . '</ul>';

        $markdown = Markdown::fromHtml($html);

        self::assertStringContainsString('[x] Klaar', $markdown);
        self::assertStringContainsString('[ ] Nog niet', $markdown);
        self::assertStringNotContainsString('SEOKITTASK', $markdown);
    }

    public function testAnIframeSurvivesAsALink(): void
    {
        // On a portfolio site the video often is the page.
        $html = '<div><iframe src="https://player.vimeo.com/video/123?dnt=1" allowfullscreen></iframe></div>';

        self::assertStringContainsString('[embed](https://player.vimeo.com/video/123?dnt=1)', Markdown::fromHtml($html));
    }

    public function testNestedListsSurvive(): void
    {
        $markdown = Markdown::fromHtml('<ul><li>Een<ul><li>Een a</li></ul></li><li>Twee</li></ul>');

        self::assertStringContainsString('- Een', $markdown);
        self::assertStringContainsString('Een a', $markdown);
        self::assertStringContainsString('- Twee', $markdown);
    }

    public function testMalformedHtmlStillComesBackAsText(): void
    {
        $markdown = Markdown::fromHtml('<p>Open <strong>vet <em>en cursief</p>');

        self::assertStringContainsString('Open', $markdown);
        self::assertStringContainsString('vet', $markdown);
    }

    public function testEmptyHtmlIsAnEmptyString(): void
    {
        self::assertSame('', Markdown::fromHtml('   '));
    }

    public function testNoRunsOfBlankLines(): void
    {
        $markdown = Markdown::fromHtml('<div><div><p>Een</p></div></div><div><p>Twee</p></div>');

        self::assertStringNotContainsString("\n\n\n", $markdown);
    }

    public function testPlainTextIsEscapedSoItStaysProse(): void
    {
        self::assertSame('pak\_2\*3', Markdown::escape('pak_2*3'));
        self::assertSame('\# geen kop', Markdown::escape('# geen kop'));
        self::assertSame('1\. geen lijst', Markdown::escape('1. geen lijst'));
        self::assertSame('\- geen opsomming', Markdown::escape('- geen opsomming'));
    }
}
