<?php

declare(strict_types=1);

namespace viesrood\seokit\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\ElementCollection;
use Stringable;
use Throwable;

/**
 * Reads an element once and comes back with the two things a page needs when
 * nobody filled anything in: a sentence of text and an image.
 *
 * One walk, not two. Description extraction and image extraction visit the
 * exact same nested structure, and on a Matrix-heavy entry that structure is
 * a handful of database queries. Doing it twice is the kind of waste that
 * only shows up on the busiest page of the site.
 */
final class ContentExtractor
{
    /**
     * Field handles that usually hold a deliberate summary. Checked before
     * anything else, so a writer who fills in "intro" gets that as the
     * description instead of the first sentence of body copy.
     *
     * Dutch and English both, because guessing right out of the box is the
     * entire promise of this plugin.
     */
    private const PREFERRED = [
        'seodescription', 'metadescription', 'summary', 'samenvatting',
        'intro', 'introtext', 'introduction', 'inleiding', 'lead', 'lede',
        'excerpt', 'teaser', 'subtitle', 'ondertitel', 'omschrijving',
        'description', 'beschrijving', 'tagline', 'payoff',
    ];

    /**
     * Handles that usually hold the image a page is "about".
     */
    private const PREFERRED_IMAGE = [
        'seoimage', 'socialimage', 'shareimage', 'ogimage',
        'featuredimage', 'heroimage', 'hero', 'headerimage',
        'image', 'afbeelding', 'beeld', 'thumbnail', 'cover',
    ];

    private string $text = '';

    private ?string $textHandle = null;

    private ?Asset $image = null;

    private ?string $imageHandle = null;

    private int $needed;

    private int $blocksVisited = 0;

    /**
     * Skip the sanity filters below.
     *
     * The guesser rejects short strings and small images because it has no
     * idea what it is looking at. When an editor names the field, it does:
     * a four-word tagline and a narrow image are then choices, not accidents,
     * and second-guessing them would make the setting a suggestion.
     */
    private bool $lenient = false;

    /**
     * Hard ceiling on nested blocks read per page. A 200-block landing page
     * has said everything it needs to say in the first few.
     */
    private const BLOCK_BUDGET = 12;

    private function __construct(int $needed)
    {
        $this->needed = $needed;
    }

    /**
     * @return array{text: string, textHandle: string|null, image: Asset|null, imageHandle: string|null}
     */
    public static function extract(ElementInterface $element, int $needed = 200): array
    {
        $extractor = new self($needed);

        try {
            $extractor->walk($element, 0);
        } catch (Throwable $exception) {
            // A single misbehaving field must never take the <head> with it.
            // Half a description beats a 500.
            Craft::warning(
                sprintf('SeoKit could not read content from element %s: %s', (string)$element->id, $exception->getMessage()),
                __METHOD__,
            );
        }

        return [
            'text' => Text::plain($extractor->text),
            'textHandle' => $extractor->textHandle,
            'image' => $extractor->image,
            'imageHandle' => $extractor->imageHandle,
        ];
    }

    /**
     * Read one named field, because somebody said to use that one.
     *
     * The guesswork in extract() is a good default and a bad promise: it can
     * only ever be right about the site it was written for. When an editor
     * points at a field, that beats every heuristic in this class, and this
     * method is how that instruction gets carried out.
     *
     * A configured field that happens to be empty on this entry returns
     * nothing, deliberately. The caller then falls back to the walk, so a
     * mapping never turns one blank field into a page with no description.
     *
     * @return array{text: string, image: Asset|null}
     */
    public static function fromField(ElementInterface $element, string $handle, int $needed = 200): array
    {
        $extractor = new self($needed);
        $extractor->lenient = true;

        try {
            $value = $element->getFieldValue($handle);
            $extractor->readValue($value, $handle, 0);
        } catch (Throwable) {
            // A handle that no longer exists on this entry type is not worth
            // an error page. The layout changed, the mapping did not, and the
            // fallback covers it.
            return ['text' => '', 'image' => null];
        }

        return [
            'text' => Text::plain($extractor->text),
            'image' => $extractor->image,
        ];
    }

    private function walk(ElementInterface $element, int $depth): void
    {
        $layout = $element->getFieldLayout();
        if ($layout === null) {
            return;
        }

        $fields = $layout->getCustomFields();

        // Preferred handles first, then everything else in layout order. The
        // second pass skips what the first already read.
        $ordered = [];
        $rest = [];

        foreach ($fields as $field) {
            $handle = strtolower(str_replace('_', '', (string)$field->handle));
            if (in_array($handle, self::PREFERRED, true) || in_array($handle, self::PREFERRED_IMAGE, true)) {
                $ordered[] = $field;
            } else {
                $rest[] = $field;
            }
        }

        foreach ([...$ordered, ...$rest] as $field) {
            if ($this->isSatisfied()) {
                return;
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

            $this->readValue($value, $handle, $depth);
        }
    }

    private function readValue(mixed $value, string $handle, int $depth): void
    {
        if ($value instanceof Asset) {
            $this->takeImage($value, $handle);

            return;
        }

        if ($value instanceof ElementQuery || $value instanceof ElementCollection) {
            $this->readElements($value, $handle, $depth);

            return;
        }

        if (is_string($value) || $value instanceof Stringable) {
            $this->takeText((string)$value, $handle);

            return;
        }

        // Anything left here is a number, a boolean or a structure. None of
        // those is a sentence: strings and Stringables were handled above.
    }

    /**
     * @param ElementQuery<int, ElementInterface>|ElementCollection<int, ElementInterface> $value
     */
    private function readElements(ElementQuery|ElementCollection $value, string $handle, int $depth): void
    {
        if ($this->blocksVisited >= self::BLOCK_BUDGET) {
            return;
        }

        // Assets come back as their own query type; anything else nested is
        // a block structure worth descending into, but only two levels deep.
        $isAssetQuery = $value instanceof ElementQuery
            ? is_a((string)$value->elementType, Asset::class, true)
            : $value->first() instanceof Asset;

        if (!$isAssetQuery && $depth >= 2) {
            return;
        }

        // Descending into blocks means we are guessing again, whatever the
        // caller asked for. "Use the contentBuilder field" is an instruction
        // about that field, not permission to treat every setting inside its
        // blocks as prose.
        if (!$isAssetQuery) {
            $this->lenient = false;
        }

        $elements = $value instanceof ElementQuery
            ? $value->limit($isAssetQuery ? 5 : self::BLOCK_BUDGET)->all()
            : $value->all();

        foreach ($elements as $nested) {
            if ($this->isSatisfied()) {
                return;
            }

            if ($nested instanceof Asset) {
                $this->takeImage($nested, $handle);

                continue;
            }

            if (!$nested instanceof ElementInterface) {
                continue;
            }

            if (++$this->blocksVisited > self::BLOCK_BUDGET) {
                return;
            }

            $this->walk($nested, $depth + 1);
        }
    }

    private function takeText(string $raw, string $handle): void
    {
        if ($this->hasEnoughText()) {
            return;
        }

        $plain = Text::plain($raw);

        // Two words is a label, not a description, and a single token with
        // no spaces at all is a setting: "section-b-padding", "default",
        // "left". Those live in block fields in numbers, and one of them in
        // a meta description is instantly recognisable as a bug.
        if (!$this->lenient && (mb_strlen($plain) < 20 || !str_contains($plain, ' '))) {
            return;
        }

        if ($plain === '') {
            return;
        }

        if ($this->text === '') {
            $this->textHandle = $handle;
            $this->text = $plain;

            return;
        }

        $this->text .= ' ' . $plain;
    }

    private function takeImage(Asset $asset, string $handle): void
    {
        if ($this->image !== null || $asset->kind !== Asset::KIND_IMAGE) {
            return;
        }

        // An icon or a logo mark is not a sharing image. Anything narrower
        // than 600px comes out as a blurry smear in a social card.
        if (!$this->lenient && $asset->getWidth() !== null && $asset->getWidth() < 600) {
            return;
        }

        $this->image = $asset;
        $this->imageHandle = $handle;
    }

    private function hasEnoughText(): bool
    {
        return mb_strlen($this->text) >= $this->needed;
    }

    private function isSatisfied(): bool
    {
        return $this->hasEnoughText() && $this->image !== null;
    }
}
