<?php

declare(strict_types=1);

namespace viesrood\seokit\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use viesrood\seokit\models\MetaResolution;

/**
 * The fallback chain in one class.
 *
 * Every layer of the resolution is a plain sequence of set() calls, and the
 * only thing that makes that work is the rule tested here: an empty value
 * never overwrites what came before it. Break that and a page with an empty
 * SEO field loses the description that was derived for it, silently.
 */
final class MetaResolutionTest extends TestCase
{
    public function testLaterValuesWin(): void
    {
        $resolution = new MetaResolution();
        $resolution->set('title', 'afgeleid', MetaResolution::SOURCE_DERIVED);
        $resolution->set('title', 'uit het veld', MetaResolution::SOURCE_FIELD);

        self::assertSame('uit het veld', $resolution->getString('title'));
        self::assertSame(MetaResolution::SOURCE_FIELD, $resolution->origin('title')['source']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function legeWaarden(): array
    {
        return [
            'lege string' => [''],
            'null' => [null],
            'lege array' => [[]],
        ];
    }

    #[DataProvider('legeWaarden')]
    public function testEmptyValuesNeverOverwrite(mixed $leeg): void
    {
        $resolution = new MetaResolution();
        $resolution->set('description', 'afgeleide tekst', MetaResolution::SOURCE_DERIVED);
        $resolution->set('description', $leeg, MetaResolution::SOURCE_FIELD);

        self::assertSame('afgeleide tekst', $resolution->getString('description'));
        self::assertSame(MetaResolution::SOURCE_DERIVED, $resolution->origin('description')['source']);
    }

    public function testStrictKeepsFalse(): void
    {
        // "Do not index this page" is exactly the falsy value that has to
        // survive, which is why it goes through setStrict.
        $resolution = new MetaResolution();
        $resolution->setStrict('indexable', true, MetaResolution::SOURCE_DERIVED);
        $resolution->setStrict('indexable', false, MetaResolution::SOURCE_FIELD);

        self::assertFalse($resolution->getBool('indexable', true));
    }

    public function testStrictKeepsAnEmptyString(): void
    {
        $resolution = new MetaResolution();
        $resolution->set('title', 'iets', MetaResolution::SOURCE_DERIVED);
        $resolution->setStrict('title', '', MetaResolution::SOURCE_TEMPLATE);

        self::assertSame('', $resolution->getString('title'));
    }

    public function testZeroIsNotTreatedAsEmpty(): void
    {
        $resolution = new MetaResolution();
        $resolution->set('imageWidth', 0, MetaResolution::SOURCE_DERIVED);

        self::assertSame(0, $resolution->get('imageWidth'));
    }

    public function testOriginCarriesTheDetail(): void
    {
        $resolution = new MetaResolution();
        $resolution->set('description', 'tekst', MetaResolution::SOURCE_DERIVED, 'chosen field "summary"');

        self::assertSame('chosen field "summary"', $resolution->origin('description')['detail']);
    }

    public function testUnknownKeysHaveNoOrigin(): void
    {
        self::assertNull((new MetaResolution())->origin('bestaatniet'));
    }

    public function testGetArrayNeverReturnsAScalar(): void
    {
        $resolution = new MetaResolution();
        $resolution->set('videos', 'geen array', MetaResolution::SOURCE_TEMPLATE);

        self::assertSame([], $resolution->getArray('videos'));
    }

    public function testRemoveDropsTheValueAndItsOrigin(): void
    {
        $resolution = new MetaResolution();
        $resolution->set('title', 'iets', MetaResolution::SOURCE_DERIVED);
        $resolution->remove('title');

        self::assertFalse($resolution->has('title'));
        self::assertNull($resolution->origin('title'));
    }

    public function testTrailReportsEveryResolvedValue(): void
    {
        $resolution = new MetaResolution();
        $resolution->set('title', 'Titel', MetaResolution::SOURCE_DERIVED, 'element title');
        $resolution->setStrict('indexable', false, MetaResolution::SOURCE_FIELD, 'seokit field');

        $trail = $resolution->trail();

        self::assertCount(2, $trail);
        self::assertSame('title', $trail[0]['key']);
        self::assertSame('false', $trail[1]['value']);
    }
}
