<?php

declare(strict_types=1);

namespace viesrood\seokit\services;

use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use viesrood\seokit\helpers\AiCrawlers;
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
        // Everything else in this method is downstream of this check. Whatever
        // the AI switches or the body below say, an environment nobody searches
        // for does not get a crawlable robots.txt. Anyone tempted to "fix" this
        // later: a staging copy inviting crawlers in is invisible from the site
        // itself, and shows up weeks later as duplicate content in the index.
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

        $ai = $this->aiCrawlerBlock();

        // An install that predates these settings has a body without the token,
        // and having to edit that text by hand before a switch does anything
        // would be a switch that silently does nothing. So: honour the token
        // where it is, append where it is not.
        if ($ai !== '' && !str_contains($body, '{aiCrawlers}')) {
            $body = rtrim($body, "\n") . "\n\n{aiCrawlers}";
        }

        return strtr($body, [
            '{sitemap}' => UrlHelper::siteUrl('sitemap.xml'),
            '{siteUrl}' => UrlHelper::siteUrl('/'),
            '{llms}' => $this->llmsUrl(),
            '{aiCrawlers}' => $ai,
        ]);
    }

    /**
     * The AI crawler block as it appears in the file, for the control panel to
     * show. Empty when both switches are off and no Content-Signal is set.
     */
    public function aiCrawlerBlock(): string
    {
        $settings = Plugin::getInstance()->getSettings();

        return AiCrawlers::block(
            $settings->blockAiTraining,
            $settings->blockAiSearch,
            $settings->contentSignal,
            $this->llmsUrl(),
        );
    }

    /**
     * The llms.txt URL, or an empty string when that file is not being served.
     *
     * Pointing at a 404 would be worse than not mentioning it: a crawler that
     * fetches it learns nothing except that this site advertises files it does
     * not have.
     */
    private function llmsUrl(): string
    {
        return Plugin::getInstance()->getSettings()->llmsTxtEnabled
            ? UrlHelper::siteUrl('llms.txt')
            : '';
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
     * Disallow rules that apply to every crawler.
     *
     * Not an error, just something worth seeing spelled out next to the
     * explanation of why it rarely does what people think.
     *
     * Rules from a named group are left out on purpose. Blocking GPTBot is a
     * deliberate act with its own switch and its own explanation, so listing
     * `/` here under "Disallow does not mean keep out of Google" would warn
     * about the one Disallow in the file that means exactly what it says. This
     * needs the group structure, not a line-by-line scan: robots.txt matching
     * is most-specific-wins, so a rule only reaches everyone when its group
     * does.
     *
     * @return array<int, string>
     */
    public function disallowRules(): array
    {
        $rules = [];
        $agents = [];
        $inRules = false;

        foreach (explode("\n", $this->render()) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (stripos($line, 'user-agent:') === 0) {
                // Consecutive User-agent lines share one group; one that
                // follows a rule starts a new group.
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }

                $agents[] = strtolower(trim(substr($line, 11)));

                continue;
            }

            if (stripos($line, 'allow:') === 0 || stripos($line, 'disallow:') === 0) {
                $inRules = true;
            }

            if (stripos($line, 'disallow:') !== 0) {
                continue;
            }

            $path = trim(substr($line, 9));

            // "Disallow:" with nothing after it means "allow everything",
            // which is the opposite of a warning.
            if ($path !== '' && in_array('*', $agents, true) && !in_array($path, $rules, true)) {
                $rules[] = $path;
            }
        }

        return $rules;
    }
}
