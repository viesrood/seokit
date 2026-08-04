<?php

declare(strict_types=1);

namespace viesrood\seokit\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use viesrood\seokit\helpers\Accept;

/**
 * Content negotiation, tested from the direction that hurts.
 *
 * The failure that matters is not "an agent got HTML"; it is "a person got
 * markdown". That one is silently broken output on a real page, so the browser
 * header is the first case here and every wildcard variation of it follows.
 *
 * The input shape is what yii\web\Request::getAcceptableContentTypes() returns:
 * content type => params, params carrying a 'q'.
 */
final class AcceptTest extends TestCase
{
    /**
     * Yii's parse of a header, without needing Yii.
     *
     * @return array<string, array<string, float>>
     */
    private static function parse(string $header): array
    {
        $acceptable = [];

        foreach (explode(',', $header) as $part) {
            $params = preg_split('/\s*;\s*/', trim($part), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $type = array_shift($params);

            if ($type === null) {
                continue;
            }

            $quality = 1.0;

            foreach ($params as $param) {
                if (str_starts_with($param, 'q=')) {
                    $quality = (float)substr($param, 2);
                }
            }

            $acceptable[$type] = ['q' => $quality];
        }

        return $acceptable;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function browserHeaders(): array
    {
        return [
            'Chrome' => ['text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8'],
            'Safari' => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
            'curl' => ['*/*'],
            'alleen een wildcard met lage q' => ['*/*;q=0.1'],
            'text-wildcard' => ['text/*'],
        ];
    }

    #[DataProvider('browserHeaders')]
    public function testARealBrowserNeverGetsMarkdown(string $header): void
    {
        self::assertFalse(Accept::prefers(self::parse($header)));
    }

    public function testAnAgentAskingForMarkdownFirstGetsIt(): void
    {
        self::assertTrue(Accept::prefers(self::parse('text/markdown,text/html;q=0.8')));
    }

    public function testMarkdownAloneGetsIt(): void
    {
        self::assertTrue(Accept::prefers(self::parse('text/markdown')));
    }

    public function testMarkdownAheadOfAWildcardGetsIt(): void
    {
        self::assertTrue(Accept::prefers(self::parse('text/markdown,*/*;q=0.5')));
    }

    public function testATieGoesToHtml(): void
    {
        // A client that values both equally is a client that renders HTML.
        self::assertFalse(Accept::prefers(self::parse('text/markdown,text/html')));
        self::assertFalse(Accept::prefers(self::parse('text/markdown;q=0.9,text/html;q=0.9')));
    }

    public function testAWildcardTieAlsoGoesToHtml(): void
    {
        self::assertFalse(Accept::prefers(self::parse('text/markdown,*/*')));
    }

    public function testMarkdownExplicitlyRefusedIsNotServed(): void
    {
        self::assertFalse(Accept::prefers(self::parse('text/markdown;q=0,text/html;q=0')));
    }

    public function testTheDeprecatedSpellingNeverBeatsExplicitHtml(): void
    {
        self::assertFalse(Accept::prefers(self::parse('text/x-markdown,text/html;q=0.1')));
        self::assertFalse(Accept::prefers(self::parse('application/markdown,text/html;q=0.1')));
    }

    public function testTheDeprecatedSpellingWinsWhenNothingElseWasAskedFor(): void
    {
        self::assertTrue(Accept::prefers(self::parse('text/x-markdown')));
    }

    public function testCasingInTheHeaderDoesNotMatter(): void
    {
        self::assertTrue(Accept::prefers(self::parse('Text/Markdown')));
    }

    public function testAnEmptyHeaderIsNotARequestForMarkdown(): void
    {
        self::assertFalse(Accept::prefers([]));
    }

    public function testAnotherTypeCanBeNegotiated(): void
    {
        $acceptable = self::parse('application/json,text/html;q=0.5');

        self::assertTrue(Accept::prefers($acceptable, 'application/json'));
        self::assertFalse(Accept::prefers($acceptable));
    }
}
