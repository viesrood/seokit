<?php

declare(strict_types=1);

namespace viesrood\seokit\web\assets\field;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Styles and behaviour for the seokit field.
 *
 * No build step. The whole thing is a few hundred lines of vanilla CSS and
 * one event listener; adding a bundler to compile that would cost more to
 * maintain than the code it compiles.
 */
class FieldAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'seokit-field.js',
        ];

        $this->css = [
            'seokit-field.css',
        ];

        parent::init();
    }
}
