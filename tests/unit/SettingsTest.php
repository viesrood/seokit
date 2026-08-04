<?php

declare(strict_types=1);

namespace viesrood\seokit\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use viesrood\seokit\models\Settings;

/**
 * The two places where what is stored and what was meant can drift apart.
 *
 * Both of these are real bugs that reached a production site. A control panel
 * lightswitch posts the string "" when it is off, which `=== false` does not
 * catch, so a section excluded from the sitemap quietly came back into it. And
 * an element select posts an array of IDs where the model wants one integer.
 */
final class SettingsTest extends TestCase
{
    public function testAnUnconfiguredEntryTypeIsIndexableAndInTheSitemap(): void
    {
        $config = (new Settings())->entryTypeConfig('onbekende-uid');

        self::assertTrue($config['indexable']);
        self::assertTrue($config['sitemap']);
        self::assertSame('auto', $config['profile']);
        self::assertSame('', $config['descriptionField']);
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function schakelaarWaarden(): array
    {
        return [
            'lege string van een uitgezette lightswitch' => ['', false],
            'string nul' => ['0', false],
            'echte false' => [false, false],
            'string een' => ['1', true],
            'echte true' => [true, true],
        ];
    }

    #[DataProvider('schakelaarWaarden')]
    public function testSitemapReadsEveryShapeOfSwitch(mixed $opgeslagen, bool $verwacht): void
    {
        $settings = new Settings();
        $settings->entryTypes = ['uid' => ['sitemap' => $opgeslagen]];

        self::assertSame($verwacht, $settings->entryTypeConfig('uid')['sitemap']);
    }

    #[DataProvider('schakelaarWaarden')]
    public function testIndexableReadsEveryShapeOfSwitch(mixed $opgeslagen, bool $verwacht): void
    {
        $settings = new Settings();
        $settings->entryTypes = ['uid' => ['indexable' => $opgeslagen]];

        self::assertSame($verwacht, $settings->entryTypeConfig('uid')['indexable']);
    }

    #[DataProvider('schakelaarWaarden')]
    public function testMarkdownReadsEveryShapeOfSwitch(mixed $opgeslagen, bool $verwacht): void
    {
        $settings = new Settings();
        $settings->entryTypes = ['uid' => ['markdown' => $opgeslagen]];

        self::assertSame($verwacht, $settings->entryTypeConfig('uid')['markdown']);
    }

    public function testMarkdownFollowsIndexable(): void
    {
        // A page asking not to be listed should not be handing out a cleaner,
        // cheaper copy of itself to read instead.
        $settings = new Settings();
        $settings->entryTypes = ['uid' => ['indexable' => false, 'markdown' => true]];

        self::assertFalse($settings->entryTypeConfig('uid')['markdown']);
    }

    public function testAnUnconfiguredEntryTypeIsOfferedAsMarkdown(): void
    {
        self::assertTrue((new Settings())->entryTypeConfig('onbekende-uid')['markdown']);
    }

    public function testExtraLinksTextareaBecomesAList(): void
    {
        $settings = new Settings();
        $settings->setAttributes([
            'llmsExtraLinks' => "https://example.com/a: A\n\n  https://example.com/b  \n",
        ], false);

        self::assertSame(['https://example.com/a: A', 'https://example.com/b'], $settings->llmsExtraLinks);
    }

    public function testSitemapFollowsIndexable(): void
    {
        // A sitemap announcing a page that says noindex is a contradiction,
        // and Search Console reports it as one.
        $settings = new Settings();
        $settings->entryTypes = ['uid' => ['indexable' => false, 'sitemap' => true]];

        self::assertFalse($settings->entryTypeConfig('uid')['sitemap']);
    }

    public function testFieldHandlesAreTrimmed(): void
    {
        $settings = new Settings();
        $settings->entryTypes = ['uid' => ['descriptionField' => '  summary  ']];

        self::assertSame('summary', $settings->entryTypeConfig('uid')['descriptionField']);
    }

    public function testAGarbledEntryTypeEntryFallsBackToDefaults(): void
    {
        $settings = new Settings();
        // Precies het punt van deze test: opgeslagen config hoeft de vorm niet
        // te hebben die het type belooft.
        // @phpstan-ignore assign.propertyType
        $settings->entryTypes = ['uid' => 'geen array'];

        self::assertTrue($settings->entryTypeConfig('uid')['sitemap']);
    }

    public function testElementSelectArrayBecomesAnInteger(): void
    {
        $settings = new Settings();
        $settings->setAttributes(['defaultSocialImageId' => ['42']], false);

        self::assertSame(42, $settings->defaultSocialImageId);
    }

    public function testAnEmptyElementSelectBecomesNull(): void
    {
        $settings = new Settings();
        $settings->defaultSocialImageId = 42;
        $settings->setAttributes(['defaultSocialImageId' => ''], false);

        self::assertNull($settings->defaultSocialImageId);
    }

    public function testSameAsTextareaBecomesAList(): void
    {
        $settings = new Settings();
        $settings->setAttributes([
            'sameAs' => "https://example.com\n\n  https://example.org  \n",
        ], false);

        self::assertSame(['https://example.com', 'https://example.org'], $settings->sameAs);
    }

    public function testSameAsSurvivesAnArray(): void
    {
        $settings = new Settings();
        $settings->setAttributes(['sameAs' => ['https://example.com']], false);

        self::assertSame(['https://example.com'], $settings->sameAs);
    }

    public function testOrganizationNameFallsBackToTheSiteName(): void
    {
        $settings = new Settings();
        $settings->siteName = 'Viesrood';

        self::assertSame('Viesrood', $settings->resolvedOrganizationName());
    }
}
