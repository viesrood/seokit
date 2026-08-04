<?php

declare(strict_types=1);

namespace viesrood\seokit\services;

use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use viesrood\seokit\Plugin;

/**
 * robots.txt, served from settings rather than a file on disk.
 *
 * The trap this is built around: people reach for `Disallow:` to keep a page
 * out of Google, and it does the opposite of what they expect. A disallowed
 * URL is never fetched, so the noindex tag on the page is never read, and the
 * bare URL can still be listed the moment anything links to it. Keep crawling
 * open, let the meta tag decide. The settings screen says so out loud, and
 * warns when a Disallow rule covers a path that is trying to noindex itself.
 */
class RobotsService extends Component
{
    /**
     * The rendered robots.txt body.
     */
    public function render(): string
    {
        if (!$this->isProduction()) {
            return implode("\n", [
                '# ' . Craft::$app->env . ' is not a production environment.',
                '# SeoKit serves a blanket disallow here, whatever the settings say.',
                '',
                'User-agent: *',
                'Disallow: /',
                '',
            ]);
        }

        $settings = Plugin::getInstance()->getSettings();
        $body = (string)$settings->robotsTxt;

        if (trim($body) === '') {
            $body = "User-agent: *\nAllow: /\n\nSitemap: {sitemap}\n";
        }

        return strtr($body, [
            '{sitemap}' => UrlHelper::siteUrl('sitemap.xml'),
            '{siteUrl}' => UrlHelper::siteUrl('/'),
        ]);
    }

    /**
     * Whether this environment is allowed to be crawled at all.
     */
    public function isProduction(): bool
    {
        $environments = Plugin::getInstance()->getSettings()->productionEnvironments;
        $current = strtolower((string)Craft::$app->env);

        foreach ($environments as $environment) {
            if (strtolower(trim((string)$environment)) === $current) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a physical robots.txt sits in the web root.
     *
     * It wins over any route, so serving one from settings while that file
     * exists changes nothing at all. Silently doing nothing is the worst
     * possible outcome for a setting, so the control panel checks.
     */
    public function physicalFile(): ?string
    {
        $path = Craft::getAlias('@webroot/robots.txt');

        if (!is_string($path)) {
            return null;
        }

        return file_exists($path) ? $path : null;
    }

    /**
     * Disallow rules found in the configured body.
     *
     * Not an error, just something worth seeing spelled out next to the
     * explanation of why it rarely does what people think.
     *
     * @return array<int, string>
     */
    public function disallowRules(): array
    {
        $rules = [];

        foreach (explode("\n", $this->render()) as $line) {
            $line = trim($line);

            if (stripos($line, 'disallow:') === 0) {
                $path = trim(substr($line, 9));

                // "Disallow:" with nothing after it means "allow everything",
                // which is the opposite of a warning.
                if ($path !== '') {
                    $rules[] = $path;
                }
            }
        }

        return $rules;
    }
}
