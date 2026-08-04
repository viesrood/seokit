<?php

declare(strict_types=1);

namespace viesrood\seokit\validators;

use Craft;
use viesrood\seokit\models\SeoData;
use yii\base\Model;
use yii\validators\Validator;

/**
 * Validation for the seokit field.
 *
 * Deliberately almost empty. A description of 240 characters is a bad
 * description, not an invalid one, and refusing to save a page over it would
 * make SEO the thing standing between an editor and publishing. Length
 * feedback belongs in the input, live, where it can be ignored.
 *
 * A canonical URL is different: a typo there points search engines at a page
 * that does not exist and quietly removes this one from the index. That is
 * worth a hard stop.
 */
class SeoDataValidator extends Validator
{
    public function validateAttribute($model, $attribute): void
    {
        if (!$model instanceof Model) {
            return;
        }

        $value = $model->$attribute;

        if (!$value instanceof SeoData || $value->canonical === '') {
            return;
        }

        $url = $value->canonical;

        if (!str_starts_with($url, '/') && filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->addError(
                $model,
                $attribute,
                Craft::t('seokit', 'The canonical URL must be a full URL or start with a slash.'),
            );
        }
    }
}
