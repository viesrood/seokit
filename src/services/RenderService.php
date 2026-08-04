<?php

declare(strict_types=1);

namespace viesrood\seokit\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\helpers\Html;
use craft\helpers\Json;
use Twig\Markup;
use viesrood\seokit\helpers\Url;
use viesrood\seokit\models\MetaResolution;
use viesrood\seokit\Plugin;

/**
 * Turns a resolution into the markup that goes in the head.
 *
 * Two things are deliberately missing from the output. There are no
 * twitter:title and twitter:description tags, because X reads the Open Graph
 * ones and duplicating them just doubles the number of places a wrong title
 * can hide. And there is no keywords tag, which has been ignored by every
 * search engine that matters since 2009.
 */
class RenderService extends Component
{
    /**
     * Markers around the rendered head.
     *
     * Rendering in place is the right default: the tags appear where you put
     * the tag, and nothing rewrites your page behind your back. But a Twig
     * layout prints the head before it renders the blocks inside it, so a
     * `craft.seokit.set()` in a block, or in an include three levels down,
     * runs after the head has already gone out. Under SEOmatic that worked,
     * because it injected into the finished response.
     *
     * So: render immediately, mark the boundaries, and if a late override does
     * turn up, swap the block for a freshly rendered one at the end of the
     * page. Position stays the template's decision, timing stops being a trap,
     * and when nothing arrives late the markers are simply removed.
     *
     * The markers are meta tags rather than the HTML comments this started
     * with, and that is not cosmetic. An HTML minifier strips comments, so on
     * any site that minifies its output the boundaries vanished before this
     * code could find them and every late override was silently dropped. Meta
     * tags survive minification; the matching below tolerates a minifier
     * reordering or unquoting their attributes.
     */
    public const MARKER_START = '<meta name="seokit-head" content="start">';

    public const MARKER_END = '<meta name="seokit-head" content="end">';

    /**
     * Loose enough to still recognise a marker a minifier has rewritten.
     */
    private const MARKER_PATTERN = '/<meta[^>]*seokit-head[^>]*>/i';

    /**
     * The complete head block: meta, link and JSON-LD.
     */
    public function head(?MetaResolution $resolution = null): Markup
    {
        $resolution ??= Plugin::getInstance()->getResolve()->current();

        if ($resolution === null) {
            return new Markup('', 'UTF-8');
        }

        $parts = [
            $this->originComment($resolution),
            $this->titleTag($resolution),
            $this->metaTags($resolution),
            $this->jsonLd($resolution),
        ];

        $html = implode("\n", array_filter(array_map('strval', $parts)));

        // From here on an override can no longer reach the page directly, so
        // anything arriving later has to be patched in at the end instead.
        Plugin::getInstance()->getResolve()->markRendered();

        return new Markup(self::MARKER_START . "\n" . $html . "\n" . self::MARKER_END, 'UTF-8');
    }

    /**
     * Replace the marked head block with a freshly resolved one, or just take
     * the markers out when nothing changed after it was written.
     */
    public function substitute(string $output): string
    {
        if (preg_match_all(self::MARKER_PATTERN, $output, $treffers, PREG_OFFSET_CAPTURE) < 2) {
            // No pair to work with. Strip a lone marker rather than ship it.
            return (string)preg_replace(self::MARKER_PATTERN, '', $output);
        }

        $start = $treffers[0][0];
        $eind = $treffers[0][1];
        $van = (int)$start[1];
        $tot = (int)$eind[1] + strlen((string)$eind[0]);

        $resolve = Plugin::getInstance()->getResolve();

        if (!$resolve->hasLateOverrides()) {
            // Nothing arrived after the head was written, so the block that is
            // already there is correct: only the markers have to go.
            return (string)preg_replace(self::MARKER_PATTERN, '', $output);
        }

        $vers = $this->stripMarkers((string)$this->head($resolve->current()));

        // substr rather than preg_replace: a meta description is exactly the
        // kind of text that contains a $ or a backslash, and preg_replace
        // would read those as backreferences.
        return substr($output, 0, $van) . $vers . substr($output, $tot);
    }

    private function stripMarkers(string $html): string
    {
        return trim((string)preg_replace(self::MARKER_PATTERN, '', $html));
    }

    public function titleTag(?MetaResolution $resolution = null): Markup
    {
        $resolution ??= Plugin::getInstance()->getResolve()->current();
        $title = $resolution?->getString('renderedTitle') ?? '';

        return new Markup('<title>' . Html::encode($title) . '</title>', 'UTF-8');
    }

    /**
     * Everything between the title and the JSON-LD.
     */
    public function metaTags(?MetaResolution $resolution = null): Markup
    {
        $resolution ??= Plugin::getInstance()->getResolve()->current();

        if ($resolution === null) {
            return new Markup('', 'UTF-8');
        }

        $lines = [];

        $description = $resolution->getString('description');
        if ($description !== '') {
            $lines[] = $this->meta('name', 'description', $description);
        }

        $canonical = $resolution->getString('canonical');
        if ($canonical !== '') {
            $lines[] = sprintf('<link rel="canonical" href="%s">', Html::encode($canonical));
        }

        $robots = $resolution->getString('robots');
        if ($robots !== '') {
            $lines[] = $this->meta('name', 'robots', $robots);
        }

        /** @var array<string, string> $alternates */
        $alternates = $resolution->get('alternates', []);
        if (is_array($alternates)) {
            foreach ($alternates as $language => $url) {
                $lines[] = sprintf(
                    '<link rel="alternate" hreflang="%s" href="%s">',
                    Html::encode((string)$language),
                    Html::encode((string)$url),
                );
            }
        }

        $markdownUrl = $this->markdownUrl($canonical);

        if ($markdownUrl !== '') {
            $lines[] = sprintf('<link rel="alternate" type="text/markdown" href="%s">', Html::encode($markdownUrl));
        }

        $lines[] = $this->meta('property', 'og:type', $resolution->getString('ogType', 'website'));
        $lines[] = $this->meta('property', 'og:title', $resolution->getString('ogTitle'));

        $ogDescription = $resolution->getString('ogDescription');
        if ($ogDescription !== '') {
            $lines[] = $this->meta('property', 'og:description', $ogDescription);
        }

        if ($canonical !== '') {
            $lines[] = $this->meta('property', 'og:url', $canonical);
        }

        $lines[] = $this->meta('property', 'og:site_name', $resolution->getString('siteName'));
        $lines[] = $this->meta('property', 'og:locale', $resolution->getString('locale'));

        $imageUrl = $resolution->getString('imageUrl');
        if ($imageUrl !== '') {
            $lines[] = $this->meta('property', 'og:image', $imageUrl);

            // Width and height let a network lay out the card before it has
            // fetched the image, which is the difference between a preview
            // that appears instantly and one that pops in a second later.
            $width = (int)$resolution->get('imageWidth', 0);
            $height = (int)$resolution->get('imageHeight', 0);

            if ($width > 0 && $height > 0) {
                $lines[] = $this->meta('property', 'og:image:width', (string)$width);
                $lines[] = $this->meta('property', 'og:image:height', (string)$height);
            }

            $alt = $resolution->getString('imageAlt');
            if ($alt !== '') {
                $lines[] = $this->meta('property', 'og:image:alt', $alt);
            }
        }

        if ($resolution->getString('ogType') === 'article') {
            $published = $resolution->getString('publishedTime');
            if ($published !== '') {
                $lines[] = $this->meta('property', 'article:published_time', $published);
            }

            $modified = $resolution->getString('modifiedTime');
            if ($modified !== '') {
                $lines[] = $this->meta('property', 'article:modified_time', $modified);
            }
        }

        $lines[] = $this->meta('name', 'twitter:card', $resolution->getString('twitterCard', 'summary_large_image'));

        $twitterSite = $resolution->getString('twitterSite');
        if ($twitterSite !== '') {
            $lines[] = $this->meta('name', 'twitter:site', $twitterSite);
        }

        return new Markup(implode("\n", array_filter($lines)), 'UTF-8');
    }

    /**
     * The `.md` twin of this page, or an empty string when there is not one.
     *
     * Only advertised when the canonical is this page's own URL. A canonical
     * pointing somewhere else says "the real version of this content lives
     * over there", and in that case the markdown of *this* URL is not what
     * should be read instead.
     */
    private function markdownUrl(string $canonical): string
    {
        if ($canonical === '' || !Plugin::getInstance()->getSettings()->markdownEnabled) {
            return '';
        }

        $element = Craft::$app->getUrlManager()->getMatchedElement();

        if (!$element instanceof ElementInterface || !Plugin::getInstance()->getMarkdown()->includes($element)) {
            return '';
        }

        $own = $element->getUrl();

        if ($own === null || rtrim(Url::absolute($own), '/') !== rtrim($canonical, '/')) {
            return '';
        }

        // The homepage has no path of its own to hang a suffix on, so it gets
        // the conventional index.md rather than a bare "/.md".
        $path = trim((string)parse_url($canonical, PHP_URL_PATH), '/');

        return rtrim($canonical, '/') . ($path === '' ? '/index.md' : '.md');
    }

    /**
     * The JSON-LD graph for this page.
     */
    public function jsonLd(?MetaResolution $resolution = null): Markup
    {
        $resolution ??= Plugin::getInstance()->getResolve()->current();
        $graph = Plugin::getInstance()->getSchema()->graph($resolution);

        if ($graph === null || $graph === []) {
            return new Markup('', 'UTF-8');
        }

        // JSON_UNESCAPED_SLASHES keeps URLs readable, JSON_UNESCAPED_UNICODE
        // keeps accented names intact. Neither affects parsing; both make the
        // block reviewable by a human, which is the only way anyone ever
        // catches a wrong @id.
        $json = Json::encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // A closing script tag inside a JSON string ends the block early. The
        // escape is invisible to a JSON parser and fatal without.
        $json = str_replace('</', '<\/', $json);

        return new Markup(
            '<script type="application/ld+json">' . $json . '</script>',
            'UTF-8',
        );
    }

    /**
     * The origin trail as an HTML comment, in devMode only.
     *
     * This is the answer to "why does this page say that", available without
     * opening the control panel. It never reaches production: shipping the
     * reasoning behind your meta tags to visitors is free information for
     * scrapers and noise for everyone else.
     */
    private function originComment(MetaResolution $resolution): string
    {
        if (!Craft::$app->getConfig()->getGeneral()->devMode) {
            return '';
        }

        if (!(Plugin::getInstance()->getSettings()->originComments)) {
            return '';
        }

        $lines = [];

        foreach ($resolution->trail() as $row) {
            // The element and the raw asset are objects, useful to the
            // renderer and meaningless in a comment.
            if (in_array($row['key'], ['element', 'image', 'breadcrumbs', 'alternates'], true)) {
                continue;
            }

            $lines[] = sprintf(
                '  %-16s %s  (%s%s)',
                $row['key'],
                self::clip($row['value']),
                $row['source'],
                $row['detail'] !== '' ? ': ' . $row['detail'] : '',
            );
        }

        if ($lines === []) {
            return '';
        }

        // A double hyphen inside a comment is invalid HTML and ends it early
        // in some parsers. Titles containing a dash are common enough that
        // this is not a theoretical concern.
        $body = str_replace('--', '- -', implode("\n", $lines));

        return "<!-- SeoKit\n" . $body . "\n-->";
    }

    private static function clip(string $value): string
    {
        return mb_strlen($value) > 90 ? mb_substr($value, 0, 90) . '…' : $value;
    }

    private function meta(string $attribute, string $name, string $content): string
    {
        if ($content === '') {
            return '';
        }

        return sprintf(
            '<meta %s="%s" content="%s">',
            $attribute,
            Html::encode($name),
            Html::encode($content),
        );
    }
}
