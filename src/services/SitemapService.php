<?php

declare(strict_types=1);

namespace viesrood\seokit\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\Entry;
use craft\fields\Assets as AssetsField;
use craft\helpers\UrlHelper;
use craft\models\Section;
use DateTimeInterface;
use Throwable;
use viesrood\seokit\helpers\Redirects;
use viesrood\seokit\helpers\Url;
use viesrood\seokit\Plugin;
use yii\caching\TagDependency;

/**
 * Sitemap index and the per-section sitemaps behind it.
 *
 * Two things are missing compared to what most plugins emit: <priority> and
 * <changefreq>. Google has said for years that it ignores both, and a number
 * that is ignored but still has to be maintained is a settings screen nobody
 * should be asked to fill in. What is left is the part that is actually read:
 * the URL, when it last changed, and the images on it.
 */
class SitemapService extends Component
{
    /**
     * Cache key prefix. The whole sitemap is regenerated on any entry save,
     * which sounds heavy until you notice the alternative is regenerating it
     * on every request from a crawler.
     */
    private const CACHE_PREFIX = 'seokit.sitemap.';

    private const CACHE_DURATION = 86400;

    /**
     * Hard ceiling per sitemap. The spec allows 50,000 URLs and 50MB; at that
     * point a site needs paginated sitemaps, which is a problem worth having
     * and not one worth pre-solving.
     */
    private const MAX_URLS = 25000;

    private const MAX_IMAGES_PER_URL = 5;

    /**
     * The sitemap index: one line per section that has public URLs.
     */
    public function index(): string
    {
        $lines = [];

        foreach ($this->sections() as $section) {
            $lastmod = $this->sectionLastModified($section);

            $lines[] = '  <sitemap>';
            $lines[] = '    <loc>' . $this->escape(UrlHelper::siteUrl('sitemap-' . $section->handle . '.xml')) . '</loc>';

            if ($lastmod !== null) {
                $lines[] = '    <lastmod>' . $lastmod->format(DATE_W3C) . '</lastmod>';
            }

            $lines[] = '  </sitemap>';
        }

        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
            ...$lines,
            '</sitemapindex>',
        ]);
    }

    /**
     * One section's sitemap, or null when that section has nothing public.
     */
    public function section(string $handle): ?string
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($handle);

        if ($section === null || !$this->isPublic($section)) {
            return null;
        }

        $cache = Craft::$app->getCache();
        $key = self::CACHE_PREFIX . $handle . '.' . Craft::$app->getSites()->getCurrentSite()->id;
        $cached = $cache->get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $xml = $this->buildSection($section);

        $cache->set($key, $xml, self::CACHE_DURATION, self::dependency());

        return $xml;
    }

    /**
     * Invalidate on any entry change.
     *
     * Both tags, and the difference matters. Craft invalidates
     * `element::<class>::*` when an entry is saved, and the plain
     * `element::<class>` only when the section or entry type structure
     * changes. Depending on the second alone is how a sitemap keeps serving
     * yesterday's pages for a day: nothing errors, it is just quietly wrong.
     */
    public static function dependency(): TagDependency
    {
        return new TagDependency([
            'tags' => [
                sprintf('element::%s::*', Entry::class),
                'element::' . Entry::class,
            ],
        ]);
    }

    private function buildSection(Section $section): string
    {
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $imageHandles = $this->assetFieldHandles($section);

        $query = Entry::find()
            ->sectionId($section->id)
            ->siteId($siteId)
            ->uri(':notempty:')
            ->limit(self::MAX_URLS);

        // Eager loading the asset fields turns "one query per entry" into one
        // query per field. On a portfolio section with a few hundred cases
        // that is the difference between a sitemap and a timeout.
        if ($imageHandles !== []) {
            $query->with($imageHandles);
        }

        $lines = [];
        $omgeleid = 0;

        foreach ($query->all() as $entry) {
            $url = $this->entryUrl($entry);

            if ($url === null || !$this->includesEntry($entry)) {
                continue;
            }

            // A URL that redirects has no business in a sitemap: Search Console
            // reports every one of them back as an error. BridgeKit knows which
            // paths those are; see the Redirects helper for why this seam
            // exists at all.
            if (Redirects::matches($url)) {
                $omgeleid++;

                continue;
            }

            $lines[] = '  <url>';
            $lines[] = '    <loc>' . $this->escape($url) . '</loc>';

            if ($entry->dateUpdated instanceof DateTimeInterface) {
                $lines[] = '    <lastmod>' . $entry->dateUpdated->format(DATE_W3C) . '</lastmod>';
            }

            foreach ($this->images($entry, $imageHandles) as $imageUrl) {
                $lines[] = '    <image:image><image:loc>' . $this->escape($imageUrl) . '</image:loc></image:image>';
            }

            $lines[] = '  </url>';
        }

        if ($omgeleid > 0) {
            Craft::info(sprintf(
                'SeoKit left %d redirected URL(s) out of the %s sitemap.',
                $omgeleid,
                $section->handle,
            ), __METHOD__);
        }

        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">',
            ...$lines,
            '</urlset>',
        ]);
    }

    /**
     * Sections with public URLs in the current site, minus the ones whose
     * entry types are all excluded.
     *
     * @return array<int, Section>
     */
    public function sections(): array
    {
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sections = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (!$this->isPublic($section, $siteId)) {
                continue;
            }

            $sections[] = $section;
        }

        return $sections;
    }

    private function isPublic(Section $section, ?int $siteId = null): bool
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        foreach ($section->getSiteSettings() as $siteSettings) {
            if ($siteSettings->siteId === $siteId && $siteSettings->hasUrls) {
                return $this->hasIncludedEntryType($section);
            }
        }

        return false;
    }

    /**
     * A section counts as included unless every one of its entry types has
     * been switched off.
     */
    private function hasIncludedEntryType(Section $section): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        foreach ($section->getEntryTypes() as $entryType) {
            if ($settings === null || $settings->entryTypeConfig($entryType->uid)['sitemap']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this specific entry counts as public knowledge.
     *
     * A sitemap listing a page that says noindex is a contradiction, and
     * Search Console reports it as one. Public rather than private because
     * llms.txt asks the same question of the same pages, and "which pages does
     * this site publish" deserves exactly one definition.
     */
    public function includesEntry(Entry $entry): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings !== null && !$settings->entryTypeConfig($entry->getType()->uid)['sitemap']) {
            return false;
        }

        $data = Plugin::getInstance()->getResolve()->fieldValue($entry);

        return !($data?->noindex === true);
    }

    private function entryUrl(Entry $entry): ?string
    {
        try {
            $url = $entry->getUrl();
        } catch (Throwable) {
            return null;
        }

        return $url === null ? null : Url::absolute($url);
    }

    /**
     * Image URLs for one entry, from its eager-loaded asset fields.
     *
     * Top-level fields only, no descent into Matrix. The images that matter
     * for image search are the ones a page is about, and those live in a
     * dedicated field far more often than they live in the third block of
     * the body.
     *
     * @param array<int, string> $handles
     * @return array<int, string>
     */
    private function images(ElementInterface $element, array $handles): array
    {
        $urls = [];

        foreach ($handles as $handle) {
            if (count($urls) >= self::MAX_IMAGES_PER_URL) {
                break;
            }

            try {
                $value = $element->getFieldValue($handle);
            } catch (Throwable) {
                continue;
            }

            $assets = $value instanceof AssetQuery ? $value->all() : $value;

            if (!is_iterable($assets)) {
                continue;
            }

            foreach ($assets as $asset) {
                if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE) {
                    continue;
                }

                try {
                    $url = $asset->getUrl();
                } catch (Throwable) {
                    continue;
                }

                // The sitemap spec wants full URLs, and a crawler reading
                // this file has no page to resolve a relative one against.
                $url = $url === null ? '' : Url::absolute($url);

                if ($url !== '' && !in_array($url, $urls, true)) {
                    $urls[] = $url;
                }

                if (count($urls) >= self::MAX_IMAGES_PER_URL) {
                    break;
                }
            }
        }

        return $urls;
    }

    /**
     * Asset field handles used by a section's entry types.
     *
     * @return array<int, string>
     */
    private function assetFieldHandles(Section $section): array
    {
        $handles = [];

        foreach ($section->getEntryTypes() as $entryType) {
            $layout = $entryType->getFieldLayout();

            foreach ($layout->getCustomFields() as $field) {
                if ($field instanceof AssetsField && !in_array((string)$field->handle, $handles, true)) {
                    $handles[] = (string)$field->handle;
                }
            }
        }

        return $handles;
    }

    private function sectionLastModified(Section $section): ?DateTimeInterface
    {
        $entry = Entry::find()
            ->sectionId($section->id)
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->uri(':notempty:')
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->one();

        return $entry?->dateUpdated;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
