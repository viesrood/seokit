<?php

declare(strict_types=1);

namespace viesrood\seokit\helpers;

use Craft;
use craft\db\Query;
use Throwable;
use viesrood\bridgekit\helpers\UrlMatcher;

/**
 * Knowing which URLs are redirected away, when BridgeKit is there to say so.
 *
 * SeoKit does not do redirects and will not grow them: BridgeKit
 * (viesrood/bridgekit) already handles 404s, static and regex redirects, and
 * automatic 301s on URI changes. But the two of them share one blind spot that
 * neither can see alone. BridgeKit knows a path redirects; it has no idea what
 * is in the sitemap. SeoKit writes the sitemap; it has no idea a path
 * redirects. Submit a redirecting URL in a sitemap and Search Console reports
 * it back as an error on every crawl.
 *
 * So this is the seam: SeoKit asks BridgeKit which paths are spoken for, and
 * leaves them out.
 *
 * Everything here is optional. Without BridgeKit installed, `available()` is
 * false and nothing else runs.
 *
 * Note that the matching uses BridgeKit's own UrlMatcher rather than a copy of
 * its rules. Two plugins agreeing on "the same path" by coincidence is a bug
 * waiting for the day one of them changes its normalisation.
 */
final class Redirects
{
    /** @var array{exact: array<string, bool>, patterns: array<int, string>}|null */
    private static ?array $cache = null;

    /**
     * Whether BridgeKit is installed, enabled and ready to be asked.
     */
    public static function available(): bool
    {
        if (!class_exists(UrlMatcher::class)) {
            return false;
        }

        try {
            if (!Craft::$app->getPlugins()->isPluginEnabled('bridgekit')) {
                return false;
            }

            return Craft::$app->getDb()->tableExists('{{%bridgekit_redirects}}');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether a URL would be redirected away.
     */
    public static function matches(string $url): bool
    {
        if (!self::available()) {
            return false;
        }

        $sources = self::sources();

        if ($sources['exact'] === [] && $sources['patterns'] === []) {
            return false;
        }

        $path = UrlMatcher::normalizePath($url);

        if (isset($sources['exact'][$path])) {
            return true;
        }

        return $sources['patterns'] !== [] && UrlMatcher::isExcluded($path, $sources['patterns']);
    }

    /**
     * The enabled redirect sources, split into exact paths and regex patterns.
     *
     * Loaded once per request: a sitemap asks this for every URL in a section,
     * and the answer cannot change halfway through building one.
     *
     * @return array{exact: array<string, bool>, patterns: array<int, string>}
     */
    public static function sources(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $exact = [];
        $patterns = [];

        try {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;

            $rows = (new Query())
                ->select(['redirectSrcUrl', 'redirectSrcUrlParsed', 'redirectMatchType'])
                ->from('{{%bridgekit_redirects}}')
                ->where(['enabled' => true])
                ->andWhere(['or', ['siteId' => null], ['siteId' => $siteId]])
                ->all();

            foreach ($rows as $row) {
                $type = (string)($row['redirectMatchType'] ?? 'exactmatch');
                $source = (string)($row['redirectSrcUrlParsed'] ?? '');

                if ($source === '') {
                    $source = (string)($row['redirectSrcUrl'] ?? '');
                }

                if ($source === '') {
                    continue;
                }

                if ($type === 'regexmatch') {
                    $patterns[] = $source;

                    continue;
                }

                $exact[UrlMatcher::normalizePath($source)] = true;
            }
        } catch (Throwable $exception) {
            // A sitemap is worth more than a perfect one. If BridgeKit's table
            // is mid-migration or otherwise unreadable, list everything rather
            // than nothing.
            Craft::warning(
                'SeoKit could not read BridgeKit redirects: ' . $exception->getMessage(),
                __METHOD__,
            );
        }

        return self::$cache = ['exact' => $exact, 'patterns' => $patterns];
    }

    /**
     * Forget the loaded sources. For tests and long-running console commands.
     */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
