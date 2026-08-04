<?php

declare(strict_types=1);

namespace viesrood\seokit\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use DateTimeImmutable;
use DateTimeInterface;
use Stringable;
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
     * Handles that hold someone's role, checked in order.
     */
    private const JOB_TITLE_FIELDS = ['role', 'jobTitle', 'jobtitle', 'functie', 'functietitel', 'position'];

    private const EMAIL_FIELDS = ['emailAddress', 'email', 'emailadres'];

    /** @var array<string, string|null> */
    private static array $authorCache = [];

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

        foreach ($this->videoNodes($resolution) as $video) {
            $nodes[] = $video;
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
     * VideoObject nodes for the videos a template registered.
     *
     * Google wants name, description, thumbnailUrl and uploadDate before it
     * will do anything with a video, and reports the ones missing a field as
     * errors in Search Console. A node that cannot meet that bar is left out
     * rather than published half-finished: an error costs attention, a missing
     * node costs nothing that was ever going to work.
     *
     * @return array<int, array<string, mixed>>
     */
    private function videoNodes(MetaResolution $resolution): array
    {
        $videos = $resolution->getArray('videos');

        if ($videos === []) {
            return [];
        }

        $paginaId = $this->pageId($resolution);
        $canonical = $resolution->getString('canonical');
        $nodes = [];
        $index = 0;

        foreach ($videos as $video) {
            if (!is_array($video)) {
                continue;
            }

            $naam = trim((string)($video['name'] ?? ''));
            $thumbnail = Url::absolute(trim((string)($video['thumbnailUrl'] ?? '')));

            // Vimeo's oEmbed response has no upload date at all, so requiring
            // one would mean never emitting a video node on a Vimeo site. The
            // page's own publication date is the honest second answer: it is
            // when this video was first published *here*, which is what the
            // property is asked to describe.
            $datum = $this->isoDate($video['uploadDate'] ?? null)
                ?? $this->isoDate($resolution->getString('publishedTime'));

            // The description may fall back to the page's own: a video block
            // without a caption still sits on a page that describes it.
            $omschrijving = Text::plain((string)($video['description'] ?? ''));
            if ($omschrijving === '') {
                $omschrijving = $resolution->getString('description');
            }

            if ($naam === '' || $thumbnail === '' || $datum === null || $omschrijving === '') {
                continue;
            }

            $node = [
                '@type' => 'VideoObject',
                '@id' => $canonical . '#video-' . (++$index),
                'name' => $naam,
                'description' => Text::truncate($omschrijving, 300),
                'thumbnailUrl' => $thumbnail,
                'uploadDate' => $datum,
                // Attached to the page rather than floating in the graph, so a
                // parser knows which page the video belongs to.
                'mainEntityOfPage' => ['@id' => $paginaId],
            ];

            $embed = trim((string)($video['embedUrl'] ?? ''));

            // Falling back to the src of the provider's own iframe. That is
            // the embed URL by definition, whoever the provider is, so this
            // needs no per-host knowledge of Vimeo or YouTube.
            if ($embed === '') {
                $embed = self::embedUrlFromCode((string)($video['embedCode'] ?? ''));
            }

            if ($embed !== '') {
                $node['embedUrl'] = $embed;
            }

            $paginaUrl = trim((string)($video['url'] ?? ''));
            if ($paginaUrl !== '') {
                $node['url'] = $paginaUrl;
            }

            $bestand = trim((string)($video['contentUrl'] ?? ''));
            if ($bestand !== '') {
                $node['contentUrl'] = $bestand;
            }

            $duur = trim((string)($video['duration'] ?? ''));
            if ($duur !== '') {
                $node['duration'] = $duur;
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * The src of the first iframe in an embed code.
     */
    private static function embedUrlFromCode(string $code): string
    {
        if ($code === '' || preg_match('/<iframe[^>]+src="([^"]+)"/i', $code, $treffer) !== 1) {
            return '';
        }

        return html_entity_decode($treffer[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * An ISO 8601 date from whatever the template handed over: oEmbed gives a
     * DateTime, a hand-written template gives a string.
     */
    private function isoDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable(trim($value)))->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
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
            ResolveService::PROFILE_PERSON => $this->personNode($base, $resolution),
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
     * A person page: the profile of someone who works here.
     *
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function personNode(array $base, MetaResolution $resolution): array
    {
        $node = [
            '@type' => 'Person',
            ...$base,
            'worksFor' => ['@id' => $this->organizationId()],
        ];

        $element = $resolution->get('element');

        if ($element instanceof ElementInterface) {
            $functie = $this->fieldText($element, self::JOB_TITLE_FIELDS);
            if ($functie !== '') {
                $node['jobTitle'] = $functie;
            }

            $email = $this->fieldText($element, self::EMAIL_FIELDS);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $node['email'] = $email;
            }
        }

        return $node;
    }

    /**
     * The first non-empty value among a short list of field handles.
     *
     * Deliberately a tight list rather than a setting: these handles are
     * unambiguous, and a schema detail buried three screens deep is a setting
     * nobody would ever find. A handle that is not there simply yields
     * nothing, which is the same as today.
     *
     * @param array<int, string> $handles
     */
    private function fieldText(ElementInterface $element, array $handles): string
    {
        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return '';
        }

        foreach ($handles as $handle) {
            if ($layout->getFieldByHandle($handle) === null) {
                continue;
            }

            try {
                $value = $element->getFieldValue($handle);
            } catch (Throwable) {
                continue;
            }

            if (is_string($value) || $value instanceof Stringable) {
                $text = Text::plain((string)$value);

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
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
            $node['author'] = $this->authorNode($author);
        }

        return $node;
    }

    /**
     * The author of an article, linked to their own page when the site has one.
     *
     * A name in an author field is a string. A name that points at a profile
     * page is a person: somewhere to look for the experience and credentials
     * behind the article. That link is the difference between claiming
     * expertise and showing it, and it is the one part of E-E-A-T that can be
     * expressed in markup at all.
     *
     * @return array<string, mixed>
     */
    private function authorNode(string $name): array
    {
        $node = ['@type' => 'Person', 'name' => $name];

        $sleutel = mb_strtolower($name);

        if (!array_key_exists($sleutel, self::$authorCache)) {
            self::$authorCache[$sleutel] = $this->findPersonPage($name);
        }

        $url = self::$authorCache[$sleutel];

        if ($url !== null) {
            $node['@id'] = $url . '#person';
            $node['url'] = $url;
        }

        return $node;
    }

    /**
     * The URL of the page describing this person, if there is one.
     */
    private function findPersonPage(string $name): ?string
    {
        $resolve = Plugin::getInstance()?->getResolve();

        if ($resolve === null) {
            return null;
        }

        $entryTypeIds = $resolve->entryTypeIdsForProfile(ResolveService::PROFILE_PERSON);

        if ($entryTypeIds === []) {
            return null;
        }

        try {
            $entry = Entry::find()
                ->typeId($entryTypeIds)
                ->title(Db::escapeParam($name))
                ->uri(':notempty:')
                ->one();

            $url = $entry?->getUrl();
        } catch (Throwable) {
            return null;
        }

        return ($url === null || $url === '') ? null : Url::absolute($url);
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

        $openingstijden = $this->openingHours();
        if ($openingstijden !== []) {
            $node['openingHours'] = $openingstijden;
        }

        return $node;
    }

    /**
     * Opening hours in schema.org notation, one entry per line.
     *
     * @return array<int, string>
     */
    private function openingHours(): array
    {
        $ruw = (string)(Plugin::getInstance()?->getSettings()->openingHours ?? '');

        if (trim($ruw) === '') {
            return [];
        }

        $regels = preg_split('/[\r\n]+/', $ruw) ?: [];

        return array_values(array_filter(array_map('trim', $regels)));
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
