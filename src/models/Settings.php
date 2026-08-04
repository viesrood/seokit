<?php

declare(strict_types=1);

namespace viesrood\seokit\models;

use craft\base\Model;

/**
 * SeoKit settings.
 *
 * Every setting here is a *deviation* from what SeoKit already derives on its
 * own. An empty settings model produces correct output: that is the design
 * rule the whole plugin follows. Nothing in here is required to get a valid
 * title, description, canonical, Open Graph set or JSON-LD graph.
 */
class Settings extends Model
{
    /**
     * Site name used in titles and in the WebSite/Organization nodes.
     *
     * Empty means the Craft site name, which is the right answer almost
     * always. Fill this in only when the Craft site name is not the name you
     * want to read in a browser tab.
     */
    public string $siteName = '';

    /**
     * Separator between the page title and the site name.
     *
     * A hyphen rather than an en or em dash on purpose: dashes get mangled in
     * enough places (feeds, mail clients, terminals) that the safe character
     * is worth more than the typographic nicety.
     */
    public string $titleSeparator = '-';

    /**
     * Title pattern. Tokens: {title}, {siteName}, {separator}.
     */
    public string $titleTemplate = '{title} {separator} {siteName}';

    /**
     * On the homepage, use the site name alone instead of the pattern above.
     * "Viesrood" beats "Home - Viesrood" in a result list.
     */
    public bool $homeTitleIsSiteName = true;

    /**
     * Run titles typed into the SEO field, or set from a template, through the
     * pattern above as well.
     *
     * On by default because it is what every other SEO plugin does, and
     * therefore what the titles already in a site were written for: an editor
     * who typed "Impactvolle branding voor Nieuwe Energie Overijssel" was
     * writing the part after the site name, not the whole tag. Turning it off
     * makes an override literally the whole title, which is the cleaner rule
     * but a surprise on a site migrated from anywhere else.
     */
    public bool $applyTitlePatternToOverrides = true;

    /**
     * Hard cap for a derived description, in characters. Google truncates
     * around 155 to 160 on desktop and shorter on mobile; the cap only
     * applies to text SeoKit writes itself, never to what someone typed.
     */
    public int $descriptionLength = 155;

    /**
     * Fallback description for pages with no usable text of their own.
     */
    public string $defaultDescription = '';

    /**
     * Asset ID of the social sharing image used when a page offers none.
     */
    public ?int $defaultSocialImageId = null;

    /**
     * Asset ID of the organization logo, used in the JSON-LD graph.
     */
    public ?int $organizationLogoId = null;

    /**
     * schema.org type of the site owner. LocalBusiness and its subtypes add
     * address and opening hours to the graph; Person is for a one-person site.
     *
     * @var 'Organization'|'LocalBusiness'|'ProfessionalService'|'Person'
     */
    public string $organizationType = 'Organization';

    /**
     * Organization name. Empty means the site name.
     */
    public string $organizationName = '';

    /**
     * Registered/legal name, when it differs from the trading name.
     */
    public string $organizationLegalName = '';

    /**
     * Profiles that verifiably belong to this organization: LinkedIn,
     * Instagram, a Wikipedia or Wikidata page, a Chamber of Commerce listing.
     *
     * This is the cheapest entity signal there is, and the one most sites
     * forget. AI answer engines lean on it to decide that the "Viesrood" in
     * their index and the one on this page are the same thing.
     *
     * @var string[]
     */
    public array $sameAs = [];

    public string $email = '';

    public string $telephone = '';

    public string $streetAddress = '';

    public string $postalCode = '';

    public string $addressLocality = '';

    public string $addressRegion = '';

    /**
     * ISO 3166-1 alpha-2 country code.
     */
    public string $addressCountry = 'NL';

    /**
     * X (formerly Twitter) handle of the site, with or without the @.
     */
    public string $twitterSite = '';

    /**
     * Opening hours in schema.org notation, one per line: `Mo-Fr 09:00-17:00`.
     *
     * Only used when the organization type is a LocalBusiness, which is the
     * only type the property means anything for.
     */
    public string $openingHours = '';

    /**
     * Robots directives added to every indexable page.
     *
     * The three defaults are the ones that actually change how a result can
     * look: a large image preview, an unlimited text snippet and an unlimited
     * video preview. Without them a search engine picks conservative limits
     * for you, and rich previews are exactly what a page needs to be picked
     * up by AI answer surfaces.
     *
     * @var string[]
     */
    public array $robotsDirectives = [
        'max-image-preview:large',
        'max-snippet:-1',
        'max-video-preview:-1',
    ];

    /**
     * Serve /sitemap.xml and the per-section sitemaps.
     */
    public bool $sitemapEnabled = true;

    /**
     * Serve /robots.txt from the text below instead of a file on disk.
     *
     * Off by default: a physical robots.txt in the web root wins over any
     * route anyway, so turning this on while that file exists would be a
     * silent no-op. The settings screen checks for the file and says so.
     */
    public bool $robotsTxtEnabled = false;

    /**
     * robots.txt body. {sitemap} expands to the absolute sitemap index URL.
     *
     * Note the absence of Disallow rules for pages you want kept out of the
     * index: a disallowed URL is never crawled, so the noindex you put on the
     * page is never read, and the bare URL can still end up listed. Keep
     * crawling open and let the meta robots tag do the work.
     */
    public string $robotsTxt = "User-agent: *\nAllow: /\n\nSitemap: {sitemap}\n";

    /**
     * Environments where the body above is served as written.
     *
     * Anywhere else gets a blanket disallow instead, whatever the body says.
     * A staging copy that invites crawlers in is how duplicate content and
     * half-finished pages end up in the index, and the mistake is invisible
     * from the site itself. Erring on the side of "not crawlable" costs
     * nothing on a environment nobody searches for.
     *
     * @var string[]
     */
    public array $productionEnvironments = ['production', 'live', 'prod'];

    /**
     * Per entry type: whether it belongs in the sitemap, which schema.org
     * profile fits it, and which fields to read. Keyed by entry type UID.
     *
     * Shape:
     *   ['<uid>' => [
     *       'indexable' => bool,
     *       'sitemap' => bool,
     *       'profile' => string,
     *       'titleField' => string,
     *       'descriptionField' => string,
     *       'imageField' => string,
     *   ]]
     *
     * A missing key, or an empty field handle, means "decide automatically",
     * which is why this is empty out of the box and why adding a section
     * never requires a visit here. Naming a field is an instruction, not a
     * requirement: if that field is empty on a given entry, the guesser fills
     * the gap rather than leaving the page without a description.
     *
     * @var array<string, array{sitemap?: bool, profile?: string, titleField?: string, descriptionField?: string, imageField?: string}>
     */
    public array $entryTypes = [];

    /**
     * Emit an HTML comment above the meta block explaining where each value
     * came from. Only ever rendered when Craft is in devMode.
     */
    public bool $originComments = true;

    /**
     * Normalise what the settings form posts before it reaches the model.
     *
     * Three shapes need it: an element select posts an array of IDs where the
     * model wants one integer, a textarea posts newline-separated text where
     * the model wants a list, and an empty element select posts an empty
     * string where the model wants null.
     *
     * @param array<string, mixed> $values
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        foreach (['defaultSocialImageId', 'organizationLogoId'] as $key) {
            if (!array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];

            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            $values[$key] = ($value === null || $value === '') ? null : (int)$value;
        }

        if (isset($values['sameAs']) && is_string($values['sameAs'])) {
            $lines = preg_split('/[\r\n]+/', $values['sameAs']) ?: [];
            $values['sameAs'] = array_values(array_filter(array_map('trim', $lines)));
        }

        if (isset($values['robotsDirectives']) && is_string($values['robotsDirectives'])) {
            $values['robotsDirectives'] = array_values(array_filter(
                array_map('trim', explode(',', $values['robotsDirectives'])),
            ));
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function defineRules(): array
    {
        return [
            [['descriptionLength'], 'integer', 'min' => 40, 'max' => 320],
            [['email'], 'email', 'skipOnEmpty' => true],
            [['organizationType'], 'in', 'range' => [
                'Organization',
                'LocalBusiness',
                'ProfessionalService',
                'Person',
            ]],
            [['titleTemplate'], 'required'],
            [['addressCountry'], 'string', 'max' => 2],
        ];
    }

    /**
     * The settings for one entry type, in the shape the rest of the plugin
     * expects.
     *
     * Everything reads entry type settings through here rather than poking at
     * the array, because what is stored depends on who wrote it. A control
     * panel lightswitch posts the string "" when it is off, a console command
     * writes a real boolean, and an older install may have no key at all.
     * Comparing `=== false` against "" is how a page that was excluded from
     * the sitemap quietly reappears in it.
     *
     * @return array{indexable: bool, sitemap: bool, profile: string, titleField: string, descriptionField: string, imageField: string}
     */
    public function entryTypeConfig(string $uid): array
    {
        $config = $this->entryTypes[$uid] ?? [];

        if (!is_array($config)) {
            $config = [];
        }

        // A whole section that must never be indexed is a setting, not a
        // template concern: a `set()` call buried in an include runs after the
        // head has already been written.
        $indexable = array_key_exists('indexable', $config)
            ? filter_var($config['indexable'], FILTER_VALIDATE_BOOL)
            : true;

        // A missing key means "not configured", which for the sitemap is
        // include. An empty string means an editor switched it off.
        $sitemap = array_key_exists('sitemap', $config)
            ? filter_var($config['sitemap'], FILTER_VALIDATE_BOOL)
            : true;

        return [
            'indexable' => $indexable,
            // A sitemap announcing a page that says noindex is a
            // contradiction, and Search Console reports it as one. Rather than
            // letting the two settings disagree, one follows the other.
            'sitemap' => $indexable && $sitemap,
            'profile' => trim((string)($config['profile'] ?? 'auto')),
            'titleField' => trim((string)($config['titleField'] ?? '')),
            'descriptionField' => trim((string)($config['descriptionField'] ?? '')),
            'imageField' => trim((string)($config['imageField'] ?? '')),
        ];
    }

    /**
     * The site name to print, falling back to Craft's own.
     */
    public function resolvedSiteName(): string
    {
        if ($this->siteName !== '') {
            return $this->siteName;
        }

        $site = \Craft::$app->getSites()->getCurrentSite();

        return (string)$site->getName();
    }

    /**
     * The organization name to print, falling back to the site name.
     */
    public function resolvedOrganizationName(): string
    {
        return $this->organizationName !== ''
            ? $this->organizationName
            : $this->resolvedSiteName();
    }
}
