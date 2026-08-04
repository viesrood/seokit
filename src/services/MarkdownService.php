<?php

declare(strict_types=1);

namespace viesrood\seokit\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\ElementCollection;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use Stringable;
use Throwable;
use viesrood\seokit\helpers\Markdown;
use viesrood\seokit\helpers\Text;
use viesrood\seokit\helpers\Url;
use viesrood\seokit\Plugin;
use yii\caching\TagDependency;

/**
 * One page as a markdown document.
 *
 * This is the part of the AI surface that earns its keep. A page of this site
 * is roughly 16,000 tokens of HTML and roughly 3,000 as markdown, and agents
 * ask for it today by sending an Accept header, without anyone having to adopt
 * a new convention.
 *
 * The field walk borrows its shape matching from ContentExtractor: Asset,
 * element query or collection, string or Stringable. One set of branches covers
 * PlainText, CKEditor, Matrix, Neo and Super Table without a case per field
 * type. Three things are deliberately different from that class:
 *
 * - **Layout order, not preferred-first.** In a meta description the best
 *   sentence wins. In a document, order is meaning.
 * - **No text budget.** ContentExtractor stops as soon as it has a sentence;
 *   here we want the page. The ceilings below exist for runaway structures,
 *   not to summarise.
 * - **No minimum length.** ContentExtractor rejects strings under 20
 *   characters to keep values like `section-b-padding` out of meta tags. A
 *   four-word heading is exactly what a document wants, so the filter is
 *   inverted: skip values that are obviously settings rather than prose.
 */
class MarkdownService extends Component
{
    private const CACHE_PREFIX = 'seokit.md.';

    private const CACHE_DURATION = 86400;

    /**
     * Ceilings for a structure that got out of hand, not an editorial limit. A
     * 400-block page is a mistake somewhere; the file should still be finite.
     */
    private const MAX_NODES = 500;

    private const MAX_CHARS = 200000;

    private const MAX_DEPTH = 6;

    private int $nodes = 0;

    private int $chars = 0;

    /**
     * The document for one element, cached until that element changes.
     */
    public function render(ElementInterface $element): string
    {
        $cache = Craft::$app->getCache();
        $key = self::CACHE_PREFIX . (string)$element->id . '.' . (string)$element->siteId;
        $cached = $cache->get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $body = $this->build($element);

        // One element, one tag. A save on any other page has no business
        // throwing away the markdown of this one.
        $cache->set($key, $body, self::CACHE_DURATION, new TagDependency([
            'tags' => ['element::' . (string)$element->id],
        ]));

        return $body;
    }

    /**
     * Whether this element is offered as markdown at all.
     */
    public function includes(ElementInterface $element): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->markdownEnabled) {
            return false;
        }

        if (!$element instanceof Entry) {
            return false;
        }

        return $settings->entryTypeConfig($element->getType()->uid)['markdown'];
    }

    private function build(ElementInterface $element): string
    {
        $this->nodes = 0;
        $this->chars = 0;

        $resolve = Plugin::getInstance()->getResolve();
        $data = $element instanceof Entry ? $resolve->fieldValue($element) : null;

        $title = trim($data->title ?? '');

        if ($title === '') {
            $title = trim((string)$element->title);
        }

        $parts = ['# ' . Markdown::escape($title)];

        $url = $this->url($element);

        if ($url !== null) {
            $parts[] = $url;
        }

        $description = trim($data->description ?? '');

        if ($description !== '') {
            $parts[] = '> ' . Markdown::escape(Text::plain($description));
        }

        foreach ($this->walk($element, 0) as $block) {
            $parts[] = $block;
        }

        // An entry with no text fields still produces a short, valid document.
        // A 404 there would show an agent an error where there is simply not
        // much to read.
        return implode("\n\n", $parts) . "\n";
    }

    /**
     * Every readable field of an element, in layout order.
     *
     * @return string[]
     */
    private function walk(ElementInterface $element, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return [];
        }

        $blocks = [];

        foreach ($layout->getCustomFields() as $field) {
            if ($this->exhausted()) {
                break;
            }

            $handle = (string)$field->handle;

            if ($handle === '') {
                continue;
            }

            try {
                $value = $element->getFieldValue($handle);
            } catch (Throwable) {
                continue;
            }

            foreach ($this->read($value, $depth) as $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * @return string[]
     */
    private function read(mixed $value, int $depth): array
    {
        if ($value instanceof Asset) {
            $block = $this->image($value);

            return $block === '' ? [] : [$block];
        }

        if ($value instanceof ElementQuery || $value instanceof ElementCollection) {
            $blocks = [];

            try {
                $nested = $value->all();
            } catch (Throwable) {
                return [];
            }

            foreach ($nested as $child) {
                if ($this->exhausted()) {
                    break;
                }

                $this->nodes++;

                foreach ($this->read($child, $depth + 1) as $block) {
                    $blocks[] = $block;
                }
            }

            return $blocks;
        }

        if ($value instanceof ElementInterface) {
            return $this->walk($value, $depth + 1);
        }

        if (is_string($value) || $value instanceof Stringable) {
            $block = $this->text((string)$value);

            return $block === '' ? [] : [$block];
        }

        // Anything left is a number, a boolean or a structure, and none of
        // those is prose. Strings were handled above.
        return [];
    }

    /**
     * One field value as a block of markdown.
     */
    private function text(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $isHtml = $value !== strip_tags($value);

        if (!$isHtml && $this->looksLikeASetting($value)) {
            return '';
        }

        $markdown = $isHtml
            ? Markdown::fromHtml($value, $this->baseUrl())
            : Markdown::escape($value);

        $this->chars += strlen($markdown);

        return trim($markdown);
    }

    /**
     * A value with no space and no punctuation in it is a CSS class, a colour
     * or a layout switch, not a sentence.
     *
     * The inverse of ContentExtractor's length filter, and for the inverse
     * reason: short prose is welcome in a document, machine values are not.
     */
    private function looksLikeASetting(string $value): bool
    {
        return preg_match('/[\s.,:;!?]/u', $value) !== 1;
    }

    private function image(Asset $asset): string
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            return '';
        }

        try {
            $url = $asset->getUrl();
        } catch (Throwable) {
            return '';
        }

        if ($url === null) {
            return '';
        }

        $alt = trim((string)($asset->alt ?? '')) ?: trim((string)$asset->title);

        return sprintf('![%s](%s)', Markdown::escape($alt), Url::absolute($url));
    }

    private function exhausted(): bool
    {
        return $this->nodes >= self::MAX_NODES || $this->chars >= self::MAX_CHARS;
    }

    private function baseUrl(): string
    {
        return rtrim(UrlHelper::baseSiteUrl(), '/');
    }

    private function url(ElementInterface $element): ?string
    {
        try {
            $url = $element->getUrl();
        } catch (Throwable) {
            return null;
        }

        return $url === null ? null : Url::absolute($url);
    }
}
