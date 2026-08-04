<?php

declare(strict_types=1);

namespace viesrood\seokit\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use Throwable;
use viesrood\seokit\helpers\Text;
use viesrood\seokit\helpers\Url;
use viesrood\seokit\models\MetaResolution;
use viesrood\seokit\Plugin;

/**
 * The JSON-LD graph.
 *
 * One @graph with @id references, not a pile of separate snippets. The
 * difference matters more than it looks: three blocks that each repeat the
 * organization name are three unconnected claims, while one graph where
 * WebPage, Article and Person all point at the same Organization node is a
 * statement about how those things relate. That relationship is what an
 * answer engine reads when it decides whether the "Viesrood" on this page is
 * the same entity it already knows about.
 *
 * Everything here is derived. There is no per-page schema editor and there
 * will not be one: hand-written JSON-LD drifts away from the page it
 * describes within a month, and wrong structured data is worse than none.
 */
class SchemaService extends Component
{
    /**
     * @return array<string, mixed>|null
     */
    public function graph(?MetaResolution $resolution): ?array
    {
        if ($resolution === null) {
            return null;
        }

        $settings = Plugin::getInstance()?->getSettings();

        if ($settings === null) {
            return null;
        }

        $nodes = [
            $this->organization(),
            $this->website(),
            $this->webPage($resolution),
        ];

        $breadcrumbs = $this->breadcrumbList($resolution);
        if ($breadcrumbs !== null) {
            $nodes[] = $breadcrumbs;
        }

        $profile = $this->profileNode($resolution);
        if ($profile !== null) {
            $nodes[] = $profile;
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => array_values(array_filter($nodes)),
        ];
    }

    public function organizationId(): string
    {
        return UrlHelper::siteUrl('/') . '#organization';
    }

    public function websiteId(): string
    {
        return UrlHelper::siteUrl('/') . '#website';
    }

    private function pageId(MetaResolution $resolution): string
    {
        return $resolution->getString('canonical', UrlHelper::siteUrl('/')) . '#webpage';
    }

    /**
     * The site owner. The one node every other node points at.
     *
     * @return array<string, mixed>
     */
    public function organization(): array
    {
        $settings = Plugin::getInstance()?->getSettings();

        $node = [
            '@type' => $settings?->organizationType ?? 'Organization',
            '@id' => $this->organizationId(),
            'name' => $settings?->resolvedOrganizationName() ?? '',
            'url' => UrlHelper::siteUrl('/'),
        ];

        if (($settings?->organizationLegalName ?? '') !== '') {
            $node['legalName'] = $settings?->organizationLegalName;
        }

        if (($settings?->email ?? '') !== '') {
            $node['email'] = $settings?->email;
        }

        if (($settings?->telephone ?? '') !== '') {
            $node['telephone'] = $settings?->telephone;
        }

        $logo = $this->imageNode($this->asset($settings?->organizationLogoId), '#logo');
        if ($logo !== null) {
            $node['logo'] = $logo;

            // Google reads `image` on an Organization, not `logo`, when it
            // picks a thumbnail. Pointing both at the same node costs one
            // line and covers both readings.
            $node['image'] = ['@id' => $logo['@id']];
        }

        $sameAs = array_values(array_filter(array_map('trim', $settings?->sameAs ?? [])));
        if ($sameAs !== []) {
            $node['sameAs'] = $sameAs;
        }

        $address = $this->addressNode();
        if ($address !== null) {
            $node['address'] = $address;
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function website(): array
    {
        $settings = Plugin::getInstance()?->getSettings();
        $site = Craft::$app->getSites()->getCurrentSite();

        return [
            '@type' => 'WebSite',
            '@id' => $this->websiteId(),
            'url' => UrlHelper::siteUrl('/'),
            'name' => $settings?->resolvedSiteName() ?? (string)$site->getName(),
            'publisher' => ['@id' => $this->organizationId()],
            'inLanguage' => (string)$site->language,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function webPage(MetaResolution $resolution): array
    {
        $site = Craft::$app->getSites()->getCurrentSite();

        $node = [
            '@type' => 'WebPage',
            '@id' => $this->pageId($resolution),
            'url' => $resolution->getString('canonical'),
            'name' => $resolution->getString('renderedTitle'),
            'isPartOf' => ['@id' => $this->websiteId()],
            'inLanguage' => (string)$site->language,
        ];

        $description = $resolution->getString('description');
        if ($description !== '') {
            $node['description'] = $description;
        }

        $image = $this->imageNode($resolution->get('image'), '#primaryimage', $resolution);
        if ($image !== null) {
            $node['primaryImageOfPage'] = $image;
        }

        if ($resolution->getArray('breadcrumbs') !== []) {
            $node['breadcrumb'] = ['@id' => $this->pageId($resolution) . '-breadcrumb'];
        }

        $published = $resolution->getString('publishedTime');
        if ($published !== '') {
            $node['datePublished'] = $published;
        }

        $modified = $resolution->getString('modifiedTime');
        if ($modified !== '') {
            $node['dateModified'] = $modified;
        }

        return $node;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function breadcrumbList(MetaResolution $resolution): ?array
    {
        $crumbs = $resolution->getArray('breadcrumbs');

        // A single crumb is the homepage pointing at itself, which tells a
        // search engine nothing it did not already know.
        if (count($crumbs) < 2) {
            return null;
        }

        $items = [];
        $position = 1;

        foreach ($crumbs as $crumb) {
            if (!is_array($crumb)) {
                continue;
            }

            $items[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => (string)($crumb['name'] ?? ''),
                'item' => (string)($crumb['url'] ?? ''),
            ];
        }

        return [
            '@type' => 'BreadcrumbList',
            '@id' => $this->pageId($resolution) . '-breadcrumb',
            'itemListElement' => $items,
        ];
    }

    /**
     * The node for whatever this page actually is: an article, a person, a
     * project, a service.
     *
     * @return array<string, mixed>|null
     */
    private function profileNode(MetaResolution $resolution): ?array
    {
        $profile = $resolution->getString('schemaProfile', ResolveService::PROFILE_WEBPAGE);

        // WebPage is already in the graph; adding a second one that says the
        // same thing is noise.
        if (in_array($profile, [ResolveService::PROFILE_WEBPAGE, ResolveService::PROFILE_NONE], true)) {
            return null;
        }

        $canonical = $resolution->getString('canonical');
        $title = $resolution->getString('title');
        $description = $resolution->getString('description');
        $image = $this->imageNode($resolution->get('image'), '#primaryimage', $resolution);

        $base = [
            '@id' => $canonical . '#' . $profile,
            'name' => $title,
            'url' => $canonical,
            'mainEntityOfPage' => ['@id' => $this->pageId($resolution)],
        ];

        if ($description !== '') {
            $base['description'] = $description;
        }

        if ($image !== null) {
            $base['image'] = ['@id' => $image['@id']];
        }

        return match ($profile) {
            ResolveService::PROFILE_ARTICLE => $this->articleNode($base, $resolution, $title),
            ResolveService::PROFILE_PERSON => [
                '@type' => 'Person',
                ...$base,
                'worksFor' => ['@id' => $this->organizationId()],
            ],
            ResolveService::PROFILE_CREATIVEWORK => [
                '@type' => 'CreativeWork',
                ...$base,
                'creator' => ['@id' => $this->organizationId()],
            ],
            ResolveService::PROFILE_SERVICE => [
                '@type' => 'Service',
                ...$base,
                'provider' => ['@id' => $this->organizationId()],
            ],
            ResolveService::PROFILE_LOCALBUSINESS => $this->localBusinessNode($base),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function articleNode(array $base, MetaResolution $resolution, string $title): array
    {
        $node = [
            '@type' => 'BlogPosting',
            ...$base,
            // headline is the one property Google documents a length limit
            // for. Over 110 characters the whole Article block can be
            // ignored, which is a silly way to lose structured data.
            'headline' => Text::truncate($title, 110),
            'publisher' => ['@id' => $this->organizationId()],
        ];

        $published = $resolution->getString('publishedTime');
        if ($published !== '') {
            $node['datePublished'] = $published;
        }

        $modified = $resolution->getString('modifiedTime');
        if ($modified !== '') {
            $node['dateModified'] = $modified;
        }

        $author = $resolution->getString('authorName');
        if ($author !== '') {
            // A named author is one of the few E-E-A-T signals that is both
            // machine readable and cheap to be honest about.
            $node['author'] = [
                '@type' => 'Person',
                'name' => $author,
            ];
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function localBusinessNode(array $base): array
    {
        $settings = Plugin::getInstance()?->getSettings();

        $node = [
            '@type' => 'LocalBusiness',
            ...$base,
            'parentOrganization' => ['@id' => $this->organizationId()],
        ];

        $address = $this->addressNode();
        if ($address !== null) {
            $node['address'] = $address;
        }

        if (($settings?->telephone ?? '') !== '') {
            $node['telephone'] = $settings?->telephone;
        }

        if (($settings?->email ?? '') !== '') {
            $node['email'] = $settings?->email;
        }

        return $node;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function addressNode(): ?array
    {
        $settings = Plugin::getInstance()?->getSettings();

        if ($settings === null || $settings->streetAddress === '' || $settings->addressLocality === '') {
            return null;
        }

        $node = [
            '@type' => 'PostalAddress',
            'streetAddress' => $settings->streetAddress,
            'addressLocality' => $settings->addressLocality,
            'addressCountry' => $settings->addressCountry,
        ];

        if ($settings->postalCode !== '') {
            $node['postalCode'] = $settings->postalCode;
        }

        if ($settings->addressRegion !== '') {
            $node['addressRegion'] = $settings->addressRegion;
        }

        return $node;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function imageNode(mixed $asset, string $fragment, ?MetaResolution $resolution = null): ?array
    {
        if (!$asset instanceof Asset) {
            return null;
        }

        try {
            $url = $asset->getUrl();
            $width = $asset->getWidth();
            $height = $asset->getHeight();
        } catch (Throwable) {
            return null;
        }

        if ($url === null || $url === '') {
            return null;
        }

        $url = Url::absolute($url);
        $base = $resolution?->getString('canonical') ?? UrlHelper::siteUrl('/');

        $node = [
            '@type' => 'ImageObject',
            '@id' => $base . $fragment,
            'url' => $url,
            'contentUrl' => $url,
        ];

        if ($width !== null && $height !== null) {
            $node['width'] = $width;
            $node['height'] = $height;
        }

        $alt = (string)($asset->alt ?? '');
        if ($alt !== '') {
            $node['caption'] = $alt;
        }

        return $node;
    }

    private function asset(?int $id): ?Asset
    {
        if ($id === null) {
            return null;
        }

        $asset = Craft::$app->getElements()->getElementById($id, Asset::class);

        return $asset instanceof Asset ? $asset : null;
    }
}
