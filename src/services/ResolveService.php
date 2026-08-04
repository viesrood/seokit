<?php

declare(strict_types=1);

namespace viesrood\seokit\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use Throwable;
use viesrood\seokit\fields\SeoField;
use viesrood\seokit\helpers\ContentExtractor;
use viesrood\seokit\helpers\SocialImage;
use viesrood\seokit\helpers\Text;
use viesrood\seokit\helpers\Url;
use viesrood\seokit\models\MetaResolution;
use viesrood\seokit\models\SeoData;
use viesrood\seokit\Plugin;

/**
 * Works out what the current page should say about itself, and remembers why.
 *
 * The resolution runs in four passes, low to high, and each pass is a plain
 * sequence of set() calls: derive from the element, apply the entry type
 * profile, apply the seokit field, apply whatever the template asked for.
 * Last writer wins. No pass needs to know what the passes before it did,
 * which is the difference between a resolver you can read and the
 * inheritance puzzle this plugin exists to replace.
 */
class ResolveService extends Component
{
    public const PROFILE_NONE = 'none';
    public const PROFILE_WEBPAGE = 'webpage';
    public const PROFILE_ARTICLE = 'article';
    public const PROFILE_CREATIVEWORK = 'creativework';
    public const PROFILE_PERSON = 'person';
    public const PROFILE_SERVICE = 'service';
    public const PROFILE_LOCALBUSINESS = 'localbusiness';

    /**
     * @return array<string, string>
     */
    public static function profiles(): array
    {
        return [
            self::PROFILE_WEBPAGE => 'WebPage',
            self::PROFILE_ARTICLE => 'Article / BlogPosting',
            self::PROFILE_CREATIVEWORK => 'CreativeWork',
            self::PROFILE_PERSON => 'Person',
            self::PROFILE_SERVICE => 'Service',
            self::PROFILE_LOCALBUSINESS => 'LocalBusiness',
            self::PROFILE_NONE => 'No page-level schema',
        ];
    }

    private ?MetaResolution $current = null;

    /** @var array<string, mixed> */
    private array $templateOverrides = [];

    /** @var array<int, array<string, mixed>> */
    private array $videos = [];

    /** @var array<string, ElementInterface|false> */
    private array $uriCache = [];

    /** @var array<string, array<int, int>> */
    private array $profileIdCache = [];

    /**
     * Whether the head has already been written into the page.
     */
    private bool $rendered = false;

    /**
     * Whether an override arrived after that, and the head therefore has to be
     * rendered again at the end of the page.
     */
    private bool $lateOverrides = false;

    public function hasLateOverrides(): bool
    {
        return $this->lateOverrides;
    }

    /**
     * Called by the renderer once head() has produced output.
     */
    public function markRendered(): void
    {
        $this->rendered = true;
    }

    /**
     * Values set from a template, applied last.
     *
     * @param array<string, mixed> $values
     */
    public function override(array $values): void
    {
        // A set() inside a {% block %} runs after the layout has printed the
        // head. Rather than losing it, remember that the head has to be
        // rendered again once the page is finished.
        if ($this->rendered) {
            $this->lateOverrides = true;
        }

        $this->templateOverrides = [...$this->templateOverrides, ...$values];

        // Something may already have asked for the resolution (a preview
        // partial, a debug dump). Drop it so the override cannot silently
        // arrive too late.
        $this->current = null;
    }

    /**
     * Register a video that appears on this page.
     *
     * Called from the template that renders the embed, because that template
     * already holds the oEmbed response: title, thumbnail, publication date.
     * Fetching it again here would mean a second HTTP request to the video
     * host for data we were just handed.
     *
     * Video blocks render inside the body, so this always arrives after the
     * head has been written. That is exactly what the late-substitution in
     * RenderService is for.
     *
     * @param array<string, mixed> $video
     */
    public function addVideo(array $video): void
    {
        if ($video === []) {
            return;
        }

        $this->videos[] = $video;

        if ($this->rendered) {
            $this->lateOverrides = true;
        }

        $this->current = null;
    }

    /**
     * The resolution for the page being rendered.
     */
    public function current(): MetaResolution
    {
        if ($this->current !== null) {
            return $this->current;
        }

        $element = Craft::$app->getUrlManager()->getMatchedElement();

        return $this->current = $this->forElement($element instanceof ElementInterface ? $element : null);
    }

    /**
     * Resolve for a specific element, ignoring the current request.
     *
     * Used by the control panel screen so an editor sees the same answer the
     * front end would give, without having to load the page.
     */
    public function forElement(
        ?ElementInterface $element,
        bool $applyTemplateOverrides = true,
        bool $includeField = true,
    ): MetaResolution {
        $resolution = new MetaResolution();

        $this->applyBase($resolution, $element);

        if ($element !== null) {
            $this->applyElement($resolution, $element);
            $this->applyProfile($resolution, $element);

            if ($includeField) {
                $this->applyField($resolution, $element);
            }
        }

        if ($applyTemplateOverrides && $this->videos !== []) {
            $resolution->set('videos', $this->videos, MetaResolution::SOURCE_TEMPLATE, 'craft.seokit.video()');
        }

        if ($applyTemplateOverrides && $this->templateOverrides !== []) {
            foreach ($this->templateOverrides as $key => $value) {
                $resolution->setStrict($key, $value, MetaResolution::SOURCE_TEMPLATE, 'craft.seokit.set()');
            }
        }

        $this->finalize($resolution);

        return $resolution;
    }

    /**
     * Site-wide values that hold for every page.
     */
    private function applyBase(MetaResolution $resolution, ?ElementInterface $element): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $site = Craft::$app->getSites()->getCurrentSite();
        $siteName = $settings->resolvedSiteName() ?? (string)$site->getName();

        $resolution
            ->set('siteName', $siteName, MetaResolution::SOURCE_SETTINGS, 'site name')
            ->set('locale', str_replace('-', '_', (string)$site->language), MetaResolution::SOURCE_DERIVED, 'site language')
            ->set('ogType', 'website', MetaResolution::SOURCE_DERIVED, 'default')
            ->set('twitterCard', 'summary_large_image', MetaResolution::SOURCE_DERIVED, 'default')
            ->setStrict('indexable', true, MetaResolution::SOURCE_DERIVED, 'default');

        if ($settings->twitterSite !== '') {
            $handle = ltrim((string)$settings->twitterSite, '@');
            $resolution->set('twitterSite', '@' . $handle, MetaResolution::SOURCE_SETTINGS, 'settings');
        }

        // Title and canonical for a page with no element behind it: a routed
        // template like /zoeken or /account. Segment-derived rather than
        // empty, because "Zoeken - Viesrood" is a better default than the
        // site name repeated on every routed page.
        $resolution->set('title', $siteName, MetaResolution::SOURCE_SETTINGS, 'site name');

        $request = Craft::$app->getRequest();
        if (!$request->getIsConsoleRequest()) {
            $path = $request->getPathInfo();
            $resolution->set(
                'canonical',
                UrlHelper::siteUrl($path),
                MetaResolution::SOURCE_DERIVED,
                'current URL without query string',
            );

            if ($element === null && $path !== '') {
                $segments = array_filter(explode('/', $path));
                $last = end($segments);

                if (is_string($last) && $last !== '') {
                    $label = ucfirst(str_replace('-', ' ', $last));
                    $resolution->set('title', $label, MetaResolution::SOURCE_DERIVED, 'URL segment');
                }
            }

            // Previews and shared drafts must never end up in an index, and
            // this is exactly the mistake nobody notices for six months.
            if ($request->getIsPreview() || $request->getToken() !== null) {
                $resolution->setStrict('indexable', false, MetaResolution::SOURCE_DERIVED, 'preview or tokened request');
            }
        }

        if ($settings->defaultDescription !== '') {
            $resolution->set(
                'description',
                (string)$settings->defaultDescription,
                MetaResolution::SOURCE_SETTINGS,
                'default description',
            );
        }

        $defaultImage = $this->defaultSocialImage($settings->defaultSocialImageId);
        if ($defaultImage !== null) {
            $resolution->set('image', $defaultImage, MetaResolution::SOURCE_SETTINGS, 'default sharing image');
        }
    }

    /**
     * Everything SeoKit can work out from the element itself.
     */
    private function applyElement(MetaResolution $resolution, ElementInterface $element): void
    {
        $resolution->set('element', $element, MetaResolution::SOURCE_DERIVED, 'matched element');

        $title = (string)$element->title;
        if ($title !== '') {
            $resolution->set('title', $title, MetaResolution::SOURCE_DERIVED, 'element title');
        }

        $isHome = false;

        try {
            $url = $element->getUrl();
            if ($url !== null) {
                $resolution->set('canonical', $url, MetaResolution::SOURCE_DERIVED, 'element URL');
                $isHome = trim((string)$element->uri, '/') === '' || $element->uri === '__home__';
            }
        } catch (Throwable) {
            // An element without a URL is fine; the request path already
            // produced a canonical.
        }

        $resolution->setStrict('isHome', $isHome, MetaResolution::SOURCE_DERIVED, 'element URI');

        // An element that is not live has no business in an index, even when
        // it is reachable (expired entries keep their URL until they are
        // resaved).
        if ($element->getStatus() !== null && $element->getStatus() !== Entry::STATUS_LIVE && !$element instanceof Asset) {
            $resolution->setStrict('indexable', false, MetaResolution::SOURCE_DERIVED, 'element status is not live');
        }

        $settings = Plugin::getInstance()->getSettings();
        $needed = $settings->descriptionLength + 40;

        $this->applyContent($resolution, $element, $needed, $settings->descriptionLength);

        if ($element instanceof Entry) {
            if ($element->postDate !== null) {
                $resolution->set('publishedTime', $element->postDate->format(DATE_ATOM), MetaResolution::SOURCE_DERIVED, 'post date');
            }

            if ($element->dateUpdated !== null) {
                $resolution->set('modifiedTime', $element->dateUpdated->format(DATE_ATOM), MetaResolution::SOURCE_DERIVED, 'date updated');
            }

            try {
                $author = $element->getAuthor();
                if ($author !== null) {
                    $resolution->set('authorName', (string)$author->getName(), MetaResolution::SOURCE_DERIVED, 'entry author');
                }
            } catch (Throwable) {
                // Sections without authors throw rather than return null.
            }
        }

        $resolution->set('breadcrumbs', $this->breadcrumbs($element), MetaResolution::SOURCE_DERIVED, 'URI structure');
        $resolution->set('alternates', $this->alternates($element), MetaResolution::SOURCE_DERIVED, 'sites in this group');
    }

    /**
     * Title, description and image out of the entry's own content.
     *
     * Two sources, in this order: the fields an editor pointed at for this
     * entry type, and the guesser for whatever is left. A configured field
     * that is empty on this particular entry falls through to the guess
     * rather than leaving the page blank, which is the whole reason the two
     * layers exist instead of one.
     *
     * The guesser only runs when something is actually still missing. An
     * entry type with both fields mapped and both filled in never walks its
     * field layout at all, which on a Matrix-heavy page is the difference
     * between a handful of queries and none.
     */
    private function applyContent(
        MetaResolution $resolution,
        ElementInterface $element,
        int $needed,
        int $descriptionLength,
    ): void {
        $mapping = $this->fieldMapping($element);

        $text = '';
        $textDetail = '';
        $image = null;
        $imageDetail = '';

        if ($mapping['title'] !== '') {
            $picked = ContentExtractor::fromField($element, $mapping['title'], $needed);

            if (trim($picked['text']) !== '') {
                $resolution->set(
                    'title',
                    trim($picked['text']),
                    MetaResolution::SOURCE_PROFILE,
                    sprintf('chosen field "%s"', $mapping['title']),
                );
            }
        }

        if ($mapping['description'] !== '') {
            $picked = ContentExtractor::fromField($element, $mapping['description'], $needed);

            if ($picked['text'] !== '') {
                $text = $picked['text'];
                $textDetail = sprintf('chosen field "%s"', $mapping['description']);
            }
        }

        if ($mapping['image'] !== '') {
            $picked = ContentExtractor::fromField($element, $mapping['image'], $needed);

            if ($picked['image'] instanceof Asset) {
                $image = $picked['image'];
                $imageDetail = sprintf('chosen field "%s"', $mapping['image']);
            }
        }

        if ($text === '' || $image === null) {
            $guessed = ContentExtractor::extract($element, $needed);

            if ($text === '' && $guessed['text'] !== '') {
                $text = $guessed['text'];
                $textDetail = sprintf('guessed from field "%s"', (string)$guessed['textHandle']);
            }

            if ($image === null && $guessed['image'] instanceof Asset) {
                $image = $guessed['image'];
                $imageDetail = sprintf('guessed from field "%s"', (string)$guessed['imageHandle']);
            }
        }

        if ($text !== '') {
            $resolution->set(
                'description',
                Text::summarize($text, $descriptionLength),
                MetaResolution::SOURCE_DERIVED,
                $textDetail,
            );
        }

        if ($image instanceof Asset) {
            $resolution->set('image', $image, MetaResolution::SOURCE_DERIVED, $imageDetail);
        }
    }

    /**
     * The fields chosen for this element's entry type, if any.
     *
     * @return array{title: string, description: string, image: string}
     */
    public function fieldMapping(ElementInterface $element): array
    {
        $empty = ['title' => '', 'description' => '', 'image' => ''];

        if (!$element instanceof Entry) {
            return $empty;
        }

        $settings = Plugin::getInstance()->getSettings();

        if ($settings === null) {
            return $empty;
        }

        $configured = $settings->entryTypeConfig($element->getType()->uid);

        return [
            'title' => $configured['titleField'],
            'description' => $configured['descriptionField'],
            'image' => $configured['imageField'],
        ];
    }

    /**
     * The entry type profile: which schema fits, and whether it belongs in
     * the sitemap.
     */
    private function applyProfile(MetaResolution $resolution, ElementInterface $element): void
    {
        $profile = $this->profileFor($element);

        $resolution->set('schemaProfile', $profile['profile'], MetaResolution::SOURCE_PROFILE, $profile['reason']);
        $resolution->setStrict('inSitemap', $profile['sitemap'], MetaResolution::SOURCE_PROFILE, $profile['reason']);

        if (!$profile['indexable']) {
            $resolution->setStrict('indexable', false, MetaResolution::SOURCE_PROFILE, $profile['reason']);
        }

        if ($profile['profile'] === self::PROFILE_ARTICLE) {
            $resolution->set('ogType', 'article', MetaResolution::SOURCE_PROFILE, 'article profile');
        }

        if ($profile['profile'] === self::PROFILE_PERSON) {
            $resolution->set('ogType', 'profile', MetaResolution::SOURCE_PROFILE, 'person profile');
        }
    }

    /**
     * Which schema profile and sitemap setting apply to an element.
     *
     * @return array{profile: string, sitemap: bool, indexable: bool, reason: string}
     */
    public function profileFor(ElementInterface $element): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($element instanceof Entry && $settings !== null) {
            $type = $element->getType();
            $configured = $settings->entryTypeConfig($type->uid);

            if ($configured['profile'] !== '' && $configured['profile'] !== 'auto') {
                return [
                    'profile' => $configured['profile'],
                    'sitemap' => $configured['sitemap'],
                    'indexable' => $configured['indexable'],
                    'reason' => sprintf('entry type "%s"', $type->handle),
                ];
            }

            return [
                'profile' => $this->guessProfile($type->handle),
                'sitemap' => $configured['sitemap'],
                'indexable' => $configured['indexable'],
                'reason' => sprintf('guessed from entry type "%s"', $type->handle),
            ];
        }

        return [
            'profile' => self::PROFILE_WEBPAGE,
            'sitemap' => true,
            'indexable' => true,
            'reason' => 'default for ' . $element::displayName(),
        ];
    }

    /**
     * Guess a schema profile from an entry type handle.
     *
     * A site that names its sections in Dutch or in English gets the right
     * answer without opening a settings screen, which is the entire reason
     * this method exists. It is a guess, it is always overridable, and being
     * wrong costs a dropdown.
     */
    /**
     * The entry type IDs that resolve to a given schema profile.
     *
     * Used to find the pages that describe people, so an article's author can
     * point at the profile page of the person who wrote it instead of being a
     * bare name. Reads the same configuration and the same guesser as
     * everything else, so a corrected profile takes effect here too.
     *
     * @return array<int, int>
     */
    public function entryTypeIdsForProfile(string $profile): array
    {
        if (isset($this->profileIdCache[$profile])) {
            return $this->profileIdCache[$profile];
        }

        $settings = Plugin::getInstance()->getSettings();
        $ids = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            foreach ($section->getEntryTypes() as $entryType) {
                $configured = $settings->entryTypeConfig($entryType->uid)['profile'] ?? 'auto';

                $resolved = ($configured !== '' && $configured !== 'auto')
                    ? $configured
                    : $this->guessProfile((string)$entryType->handle);

                if ($resolved === $profile) {
                    $ids[] = (int)$entryType->id;
                }
            }
        }

        return $this->profileIdCache[$profile] = $ids;
    }

    private function guessProfile(string $handle): string
    {
        $needle = strtolower($handle);

        $map = [
            self::PROFILE_ARTICLE => ['article', 'artikel', 'blog', 'news', 'nieuws', 'post', 'story', 'verhaal'],
            self::PROFILE_PERSON => ['team', 'person', 'people', 'medewerker', 'auteur', 'author', 'collega'],
            self::PROFILE_CREATIVEWORK => ['work', 'werk', 'case', 'project', 'portfolio', 'referentie'],
            self::PROFILE_SERVICE => ['service', 'dienst', 'expertise', 'aanbod', 'oplossing'],
            self::PROFILE_LOCALBUSINESS => ['contact', 'locatie', 'vestiging', 'location'],
        ];

        foreach ($map as $profile => $needles) {
            foreach ($needles as $candidate) {
                if (str_contains($needle, $candidate)) {
                    return $profile;
                }
            }
        }

        return self::PROFILE_WEBPAGE;
    }

    /**
     * What an editor typed into the seokit field.
     */
    private function applyField(MetaResolution $resolution, ElementInterface $element): void
    {
        $data = $this->fieldValue($element);

        if ($data === null) {
            return;
        }

        $label = 'seokit field on this entry';

        if ($data->title !== '') {
            $resolution->set('title', $data->title, MetaResolution::SOURCE_FIELD, $label);
            $resolution->setStrict('titleIsExact', true, MetaResolution::SOURCE_FIELD, $label);
        }

        if ($data->description !== '') {
            $resolution->set('description', $data->description, MetaResolution::SOURCE_FIELD, $label);
        }

        if ($data->canonical !== '') {
            $resolution->set('canonical', $data->canonical, MetaResolution::SOURCE_FIELD, $label);
        }

        if ($data->noindex) {
            $resolution->setStrict('indexable', false, MetaResolution::SOURCE_FIELD, $label);
        }

        $image = $data->getSocialImage();
        if ($image !== null) {
            $resolution->set('image', $image, MetaResolution::SOURCE_FIELD, $label);
        }
    }

    /**
     * The seokit field value on an element, if it has one.
     */
    public function fieldValue(ElementInterface $element): ?SeoData
    {
        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return null;
        }

        foreach ($layout->getCustomFields() as $field) {
            if (!$field instanceof SeoField) {
                continue;
            }

            try {
                $value = $element->getFieldValue((string)$field->handle);
            } catch (Throwable) {
                return null;
            }

            return $value instanceof SeoData ? $value : null;
        }

        return null;
    }

    /**
     * Compose the values that depend on the rest: the printed title, the
     * social variants and the robots directive.
     */
    private function finalize(MetaResolution $resolution): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $siteName = $resolution->getString('siteName');
        $title = $resolution->getString('title');

        // An override is used verbatim; a derived title gets the site name
        // appended, unless it is the homepage, where the site name alone
        // reads better than "Home - Viesrood".
        // An override only escapes the pattern when the site says overrides
        // are the whole title. Otherwise it is treated like any other title,
        // which is what a writer coming from SEOmatic or Yoast expects.
        $literalOverrides = !$settings->applyTitlePatternToOverrides;
        $titleSource = $resolution->origin('title')['source'] ?? '';
        $isOverride = $resolution->getBool('titleIsExact')
            || $titleSource === MetaResolution::SOURCE_TEMPLATE;

        $needsTemplate = !($literalOverrides && $isOverride);

        if ($needsTemplate && $title !== '' && $siteName !== '') {
            if ($resolution->getBool('isHome') && $settings->homeTitleIsSiteName) {
                $title = $siteName;
            } elseif ($title !== $siteName) {
                $title = strtr((string)$settings->titleTemplate, [
                    '{title}' => $title,
                    '{siteName}' => $siteName,
                    '{separator}' => (string)$settings->titleSeparator,
                ]);
            }
        }

        $resolution->set('renderedTitle', trim($title), MetaResolution::SOURCE_DERIVED, 'title pattern');

        // Social title and description follow the page unless something set
        // them apart. Divergence here is a feature nobody asked for and a
        // bug everybody reports.
        if (!$resolution->has('ogTitle')) {
            $resolution->set('ogTitle', $resolution->getString('renderedTitle'), MetaResolution::SOURCE_DERIVED, 'follows the page title');
        }

        if (!$resolution->has('ogDescription') && $resolution->has('description')) {
            $resolution->set('ogDescription', $resolution->getString('description'), MetaResolution::SOURCE_DERIVED, 'follows the description');
        }

        // Crawlers read these outside the page they came from, so anything
        // root-relative has to be absolute by the time it is written.
        $canonical = $resolution->getString('canonical');
        if ($canonical !== '') {
            // Rewriting the value must not rewrite its history: an override
            // that came from the field still came from the field.
            $origin = $resolution->origin('canonical');
            $resolution->set(
                'canonical',
                Url::absolute($canonical),
                $origin['source'] ?? MetaResolution::SOURCE_DERIVED,
                $origin['detail'] ?? '',
            );
        }

        $image = $resolution->get('image');
        if ($image instanceof Asset) {
            $card = SocialImage::card($image);

            if ($card !== null) {
                $resolution->set('imageUrl', Url::absolute($card['url']), MetaResolution::SOURCE_DERIVED, 'cropped to 1200x630');
                $resolution->set('imageWidth', $card['width'], MetaResolution::SOURCE_DERIVED, 'transform');
                $resolution->set('imageHeight', $card['height'], MetaResolution::SOURCE_DERIVED, 'transform');
                $resolution->set('imageAlt', $card['alt'], MetaResolution::SOURCE_DERIVED, 'asset alt text');
            }
        } elseif (is_string($image) && $image !== '') {
            $resolution->set('imageUrl', Url::absolute($image), MetaResolution::SOURCE_TEMPLATE, 'URL passed in');
        }

        // A template that spelled out its own robots directive means it, and
        // composing one over the top of it would make the override look like
        // it did nothing.
        if (($resolution->origin('robots')['source'] ?? '') !== MetaResolution::SOURCE_TEMPLATE) {
            $directives = $resolution->getBool('indexable', true)
                ? ['index', 'follow', ...$settings->robotsDirectives]
                : ['noindex', 'nofollow'];

            $resolution->set('robots', implode(', ', $directives), MetaResolution::SOURCE_DERIVED, 'indexable state plus settings');
        }
    }

    /**
     * Breadcrumb trail: home, then whatever sits above this page.
     *
     * Structure ancestors when the section has them, otherwise the URI is
     * walked segment by segment and each segment looked up as an element.
     * Segments that resolve to nothing are dropped rather than guessed at:
     * a crumb pointing at a 404 is worse than a shorter trail.
     *
     * @return array<int, array{name: string, url: string}>
     */
    private function breadcrumbs(ElementInterface $element): array
    {
        $home = [[
            'name' => Craft::t('seokit', 'Home'),
            'url' => UrlHelper::siteUrl('/'),
        ]];

        $uri = trim((string)$element->uri, '/');

        if ($uri === '' || $element->uri === '__home__') {
            return $home;
        }

        $crumbs = $home;
        $segments = explode('/', $uri);
        $path = '';

        foreach ($segments as $index => $segment) {
            $path = $path === '' ? $segment : $path . '/' . $segment;
            $isLast = $index === count($segments) - 1;

            if ($isLast) {
                $crumbs[] = [
                    'name' => (string)$element->title,
                    'url' => UrlHelper::siteUrl($path),
                ];

                continue;
            }

            $parent = $this->elementByUri($path);

            if ($parent !== null) {
                $crumbs[] = [
                    'name' => (string)$parent->title,
                    'url' => UrlHelper::siteUrl($path),
                ];
            }
        }

        return $crumbs;
    }

    private function elementByUri(string $uri): ?ElementInterface
    {
        if (array_key_exists($uri, $this->uriCache)) {
            $cached = $this->uriCache[$uri];

            return $cached === false ? null : $cached;
        }

        try {
            $element = Craft::$app->getElements()->getElementByUri($uri, null, true);
        } catch (Throwable) {
            $element = null;
        }

        $this->uriCache[$uri] = $element ?? false;

        return $element;
    }

    /**
     * hreflang alternates for the sites this element also lives on.
     *
     * Skipped entirely on a single-site install, which is the common case and
     * would otherwise cost a query per render for nothing.
     *
     * @return array<string, string>
     */
    private function alternates(ElementInterface $element): array
    {
        $sites = Craft::$app->getSites();
        $group = $sites->getCurrentSite()->getGroup();
        $groupSites = $group->getSites();

        if (count($groupSites) < 2) {
            return [];
        }

        $alternates = [];

        foreach ($groupSites as $site) {
            try {
                $localized = Craft::$app->getElements()->getElementById(
                    (int)$element->id,
                    $element::class,
                    $site->id,
                );

                $url = $localized?->getUrl();
            } catch (Throwable) {
                $url = null;
            }

            if ($url !== null && $url !== '') {
                $alternates[(string)$site->language] = $url;
            }
        }

        return count($alternates) > 1 ? $alternates : [];
    }

    private function defaultSocialImage(?int $assetId): ?Asset
    {
        if ($assetId === null) {
            return null;
        }

        $asset = Craft::$app->getElements()->getElementById($assetId, Asset::class);

        return $asset instanceof Asset ? $asset : null;
    }
}
