<?php

declare(strict_types=1);

namespace viesrood\seokit\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Json;
use viesrood\seokit\models\SeoData;
use viesrood\seokit\Plugin;
use viesrood\seokit\validators\SeoDataValidator;
use viesrood\seokit\web\assets\field\FieldAsset;
use yii\db\Schema;

/**
 * The one SeoKit field: five deviations from what the page already says.
 *
 * Note what is not in here. No keywords (dead since 2009), no per-page
 * schema editor, no separate Open Graph title and description, no priority
 * or change frequency. Every one of those is a decision an editor would have
 * to make on every page, and none of them changes the outcome. The plugin
 * asks for five things and works the rest out.
 *
 * Empty inputs are not missing data: the placeholder in each box is the real
 * value the page will use, pulled from the live resolution. Filling one in is
 * the exception, not the chore.
 */
class SeoField extends Field
{
    public static function displayName(): string
    {
        return Craft::t('seokit', 'SEO');
    }

    public static function icon(): string
    {
        return 'magnifying-glass';
    }

    public static function phpType(): string
    {
        return SeoData::class;
    }

    public static function dbType(): array|string|null
    {
        return Schema::TYPE_JSON;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof SeoData) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $value = Json::decodeIfJson($value);
        }

        $data = new SeoData();

        if (!is_array($value)) {
            return $data;
        }

        $data->title = trim((string)($value['title'] ?? ''));
        $data->description = trim((string)($value['description'] ?? ''));
        $data->canonical = trim((string)($value['canonical'] ?? ''));
        $data->noindex = (bool)($value['noindex'] ?? false);

        // The element select posts an array of IDs; the stored value is a
        // plain integer.
        $image = $value['socialImageId'] ?? null;
        if (is_array($image)) {
            $image = $image[0] ?? null;
        }

        $data->socialImageId = ($image === null || $image === '') ? null : (int)$image;

        return $data;
    }

    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): mixed
    {
        // The control panel posts "show in search engines", which is the
        // inverse of what gets stored. Flipping it here rather than in the
        // template keeps the stored meaning ("noindex") unambiguous while the
        // editor reads a switch that cannot be misread.
        if (is_array($value) && array_key_exists('indexable', $value)) {
            $value['noindex'] = !((bool)$value['indexable']);
            unset($value['indexable']);
        }

        return $this->normalizeValue($value, $element);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if (!$value instanceof SeoData) {
            return null;
        }

        // Empty stays empty rather than becoming a row of blank strings, so
        // "has anyone touched this page's SEO" is answerable from the data.
        return $value->isEmpty() ? null : $value->jsonSerialize();
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(FieldAsset::class);

        if (!$value instanceof SeoData) {
            $value = new SeoData();
        }

        // What the page says about itself with this field left alone. These
        // become the placeholders, so an editor sees the real title and
        // description rather than an empty box that implies something is
        // missing.
        $derived = Plugin::getInstance()->getResolve()->forElement($element, false, false);

        return $view->renderTemplate('seokit/_field', [
            'id' => $view->formatInputId($this->handle),
            'namespacedId' => $view->namespaceInputId($view->formatInputId($this->handle)),
            'name' => $this->handle,
            'field' => $this,
            'value' => $value,
            'element' => $element,
            'derived' => $derived,
            'descriptionLength' => Plugin::getInstance()->getSettings()->descriptionLength,
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    public function getElementValidationRules(): array
    {
        return [
            [SeoDataValidator::class],
        ];
    }
}
