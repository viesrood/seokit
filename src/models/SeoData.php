<?php

declare(strict_types=1);

namespace viesrood\seokit\models;

use Craft;
use craft\base\Model;
use craft\elements\Asset;
use JsonSerializable;

/**
 * The value of a seokit field: five deviations, nothing more.
 *
 * Five is the whole point. Every field added here is a field a writer has to
 * form an opinion about on every page, and the marginal ranking value of the
 * sixth one is nil. Everything else SeoKit needs it works out itself.
 */
class SeoData extends Model implements JsonSerializable
{
    /**
     * Overrides the derived <title>. Written out as typed, no site name
     * appended: an override is an override.
     */
    public string $title = '';

    /**
     * Overrides the derived meta description.
     */
    public string $description = '';

    /**
     * Asset ID of the sharing image for this page.
     */
    public ?int $socialImageId = null;

    /**
     * Keep this page out of search results.
     *
     * Phrased in the control panel as a positive ("Show in search engines")
     * because a switch you have to read twice is a switch that gets set
     * wrong, and this particular mistake takes a page out of Google.
     */
    public bool $noindex = false;

    /**
     * Canonical URL override. Only for the genuine duplicate-content case;
     * SeoKit points a page at itself otherwise.
     */
    public string $canonical = '';

    private ?Asset $socialImage = null;

    private bool $socialImageLoaded = false;

    public function getSocialImage(): ?Asset
    {
        if ($this->socialImageLoaded) {
            return $this->socialImage;
        }

        $this->socialImageLoaded = true;

        if ($this->socialImageId === null) {
            return null;
        }

        $asset = Craft::$app->getElements()->getElementById($this->socialImageId, Asset::class);
        $this->socialImage = $asset instanceof Asset ? $asset : null;

        return $this->socialImage;
    }

    /**
     * True when nothing was filled in, so the control panel can show the
     * "everything derived" state rather than five empty boxes pretending to
     * be a problem.
     */
    public function isEmpty(): bool
    {
        return $this->title === ''
            && $this->description === ''
            && $this->socialImageId === null
            && $this->canonical === ''
            && $this->noindex === false;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'socialImageId' => $this->socialImageId,
            'noindex' => $this->noindex,
            'canonical' => $this->canonical,
        ];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function defineRules(): array
    {
        return [
            [['title', 'description', 'canonical'], 'string'],
            [['socialImageId'], 'integer'],
            [['noindex'], 'boolean'],
            [['canonical'], 'url', 'skipOnEmpty' => true],
        ];
    }
}
