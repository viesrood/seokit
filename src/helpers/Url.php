<?php

declare(strict_types=1);

namespace viesrood\seokit\helpers;

use craft\helpers\UrlHelper;

/**
 * Making URLs absolute, which for meta tags is not optional.
 *
 * Craft happily returns root-relative URLs ("/assets/…") depending on how a
 * volume and the site base URL are set up, and in a page body that is fine.
 * In a canonical, an og:image or a JSON-LD node it is not: those are read
 * outside the context of the page, by crawlers and scrapers that have no base
 * to resolve against. A relative og:image is simply a missing preview image.
 */
final class Url
{
    public static function absolute(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (UrlHelper::isAbsoluteUrl($url) || UrlHelper::isProtocolRelativeUrl($url)) {
            return $url;
        }

        // Data URIs and mailto: links have no business here, but neither
        // should they be mangled into a path.
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            return $url;
        }

        $host = rtrim(UrlHelper::hostInfo(UrlHelper::baseSiteUrl()), '/');

        if ($host === '') {
            return $url;
        }

        // The root-relative URL already carries any subfolder the install
        // lives in, so only scheme and host are missing.
        return $host . '/' . ltrim($url, '/');
    }
}
