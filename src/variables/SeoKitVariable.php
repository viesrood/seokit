<?php

declare(strict_types=1);

namespace viesrood\seokit\variables;

use craft\base\ElementInterface;
use craft\helpers\UrlHelper;
use Twig\Markup;
use viesrood\seokit\models\MetaResolution;
use viesrood\seokit\Plugin;

/**
 * craft.seokit
 *
 * The whole front-end surface of the plugin is one call:
 *
 *     {{ craft.seokit.head() }}
 *
 * Everything else here exists for the pages that need to say something the
 * derivation cannot know, and for the rare template that wants to lay the
 * tags out itself.
 */
class SeoKitVariable
{
    /**
     * Title, meta, link and JSON-LD in one go. Put this in your <head>.
     */
    public function head(): Markup
    {
        return Plugin::getInstance()->getRender()->head() ?? new Markup('', 'UTF-8');
    }

    public function title(): Markup
    {
        return Plugin::getInstance()->getRender()->titleTag() ?? new Markup('', 'UTF-8');
    }

    public function meta(): Markup
    {
        return Plugin::getInstance()->getRender()->metaTags() ?? new Markup('', 'UTF-8');
    }

    public function jsonLd(): Markup
    {
        return Plugin::getInstance()->getRender()->jsonLd() ?? new Markup('', 'UTF-8');
    }

    /**
     * Override resolved values from a template, before head() is called.
     *
     *     {% do craft.seokit.set({
     *         title: 'Postduif - link verlopen',
     *         indexable: false,
     *     }) %}
     *
     * Keys are the ones in the resolution: title, description, canonical,
     * indexable, image, ogType. Anything set here wins over everything else,
     * because a template is the last word by definition.
     *
     * @param array<string, mixed> $values
     */
    public function set(array $values): void
    {
        Plugin::getInstance()->getResolve()->override($values);
    }

    /**
     * Register a video on this page so it ends up in the JSON-LD graph.
     *
     *     {% do craft.seokit.video({
     *         name: media.title,
     *         description: block.desc,
     *         thumbnailUrl: media.image,
     *         uploadDate: media.publishedTime,
     *         embedUrl: block.video.url,
     *     }) %}
     *
     * Call it from the template that renders the embed: it already has the
     * oEmbed response, and asking the video host for it a second time is a
     * request nobody needs. Missing a thumbnail or an upload date means the
     * video is left out rather than published incomplete.
     *
     * @param array<string, mixed> $video
     */
    public function video(array $video): void
    {
        Plugin::getInstance()->getResolve()->addVideo($video);
    }

    /**
     * Shorthand for the single most common override.
     */
    public function noindex(): void
    {
        $this->set(['indexable' => false]);
    }

    /**
     * The resolution behind the current page, for templates that want to
     * render their own tags or show a debug panel.
     */
    public function resolution(): ?MetaResolution
    {
        return Plugin::getInstance()->getResolve()->current();
    }

    /**
     * Resolve another element without rendering it: useful for a card that
     * wants the same description the target page would show.
     */
    public function forElement(ElementInterface $element): ?MetaResolution
    {
        return Plugin::getInstance()->getResolve()->forElement($element, false);
    }

    public function sitemapUrl(): string
    {
        return UrlHelper::siteUrl('sitemap.xml');
    }
}
