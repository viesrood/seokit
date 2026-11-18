<?php

declare(strict_types=1);

namespace viesrood\seokit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\seokit\helpers\SocialImage;

final class SocialImageTest extends TestCase
{
    public function testGifIsNeverCroppedDuringARequest(): void
    {
        // An animated GIF is unpacked frame by frame before it is cropped.
        // That killed the request for a page whose first upload was one.
        self::assertFalse(SocialImage::canTransform('gif'));
        self::assertFalse(SocialImage::canTransform('GIF'));
    }

    public function testOrdinaryImagesAreCropped(): void
    {
        foreach (['jpg', 'jpeg', 'png', 'webp', 'avif'] as $extension) {
            self::assertTrue(SocialImage::canTransform($extension), $extension);
        }
    }
}
