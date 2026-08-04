<?php

declare(strict_types=1);

namespace viesrood\seokit\helpers;

use craft\elements\Asset;
use Throwable;

/**
 * One asset in, one ready-to-share card image out.
 */
final class SocialImage
{
    /**
     * The size every network crops from. 1200x630 is the 1.91:1 box Facebook,
     * LinkedIn, WhatsApp and X all cut their preview from, so producing it
     * ourselves is the only way to know which part of the photo survives.
     */
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    /**
     * @return array{url: string, width: int, height: int, alt: string}|null
     */
    public static function card(?Asset $asset): ?array
    {
        if ($asset === null || $asset->kind !== Asset::KIND_IMAGE) {
            return null;
        }

        $transform = [
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'mode' => 'crop',
            'format' => 'jpg',
            'quality' => 82,
        ];

        $url = null;
        $width = null;
        $height = null;

        try {
            // Craft honours the asset's focal point here, which is the whole
            // reason for transforming rather than handing over the original:
            // a portrait photo cropped from the centre decapitates people.
            //
            // `immediately` is not optional. Without it Craft hands back a
            // /actions/assets/generate-transform URL and generates the file on
            // first hit. A browser follows that redirect happily; a social
            // scraper often does not, so the card silently comes out blank on
            // exactly the first share, which is the one that matters.
            $url = $asset->getUrl($transform, true);
            $width = $asset->getWidth($transform);
            $height = $asset->getHeight($transform);
        } catch (Throwable) {
            $url = null;
        }

        if ($url === null || $url === '') {
            try {
                $url = $asset->getUrl();
                $width = $asset->getWidth();
                $height = $asset->getHeight();
            } catch (Throwable) {
                return null;
            }
        }

        if ($url === null || $url === '') {
            return null;
        }

        return [
            'url' => $url,
            'width' => (int)($width ?? self::WIDTH),
            'height' => (int)($height ?? self::HEIGHT),
            'alt' => (string)($asset->alt ?? $asset->title ?? ''),
        ];
    }
}
