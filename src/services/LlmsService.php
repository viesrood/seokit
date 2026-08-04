<?php

declare(strict_types=1);

namespace viesrood\seokit\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\models\Section;
use Throwable;
use viesrood\seokit\helpers\ContentExtractor;
use viesrood\seokit\helpers\LlmsDocument;
use viesrood\seokit\helpers\Redirects;
use viesrood\seokit\helpers\Text;
use viesrood\seokit\helpers\Url;
use viesrood\seokit\Plugin;

/**
 * /llms.txt: the site as a language model would like to receive it.
 *
 * Set expectations before reading further. Measured over 90 days, 84 of 62,100
 * AI bot requests went to this file, and no major vendor has publicly committed
 * to reading it. It exists here because it costs one route and one cache entry,
 * not because it is the thing that makes a site legible to a model. Content
 * negotiation on the Accept header does far more work for far less ceremony.
 *
 * What makes the file worth anything at all is the caps below. An llms.txt with
 * every URL on a large site is a sitemap with worse syntax: too long to fit in
 * a context window, so it gets truncated by whoever reads it, at a point they
 * pick and nobody here can see. Cutting it here instead, visibly, with a line
 * saying how much was left out and where the rest lives, is the whole design.
 *
 * The document itself is assembled by LlmsDocument. This class is only the part
 * that has to ask Craft questions.
 */
class LlmsService extends Component
{
    private const CACHE_PREFIX = 'seokit.llms.';

    private const CACHE_DURATION = 86400;

    /**
     * Per section, and overall. Two ceilings rather than one, so a single large
     * section cannot crowd every other section out of the file.
     */
    private const MAX_ENTRIES_PER_SECTION = 200;

    private const MAX_ENTRIES = 2000;

    /**
     * The whole file, cached until an entry is saved.
     */
    public function render(): string
    {
        $cache = Craft::$app->getCache();
        $key = self::CACHE_PREFIX . Craft::$app->getSites()->getCurrentSite()->id;
        $cached = $cache->get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $body = $this->build();

        $cache->set($key, $body, self::CACHE_DURATION, SitemapService::dependency());

        return $body;
    }

    /**
     * How many sections the file will list, for the control panel.
     */
    public function sectionCount(): int
    {
        return count(Plugin::getInstance()->getSitemap()->sections());
    }

    private function build(): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $blocks = [];
        $budget = self::MAX_ENTRIES;

        foreach (Plugin::getInstance()->getSitemap()->sections() as $section) {
            if ($budget <= 0) {
                break;
            }

            $block = $this->sectionBlock($section, min(self::MAX_ENTRIES_PER_SECTION, $budget));

            if ($block === null) {
                continue;
            }

            $blocks[] = $block['text'];
            $budget -= $block['used'];
        }

        $optional = LlmsDocument::optional($settings->llmsExtraLinks);

        if ($optional !== '') {
            $blocks[] = $optional;
        }

        $summary = trim($settings->llmsSummary) !== ''
            ? $settings->llmsSummary
            : $settings->defaultDescription;

        return LlmsDocument::assemble(
            $settings->resolvedSiteName(),
            Text::plain($summary),
            $settings->llmsIntro,
            $blocks,
        );
    }

    /**
     * One section as a heading plus its pages, or null when nothing in it is
     * public.
     *
     * @return array{text: string, used: int}|null
     */
    private function sectionBlock(Section $section, int $limit): ?array
    {
        $sitemap = Plugin::getInstance()->getSitemap();

        // One past the limit, which is all it takes to know the list was cut.
        // Loading the rest to count them would be reading a section in full for
        // the sake of a number in the one line that says we did not.
        $entries = Entry::find()
            ->sectionId($section->id)
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->uri(':notempty:')
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit($limit + 1)
            ->all();

        $length = Plugin::getInstance()->getSettings()->descriptionLength;
        $lines = [];
        $truncated = false;

        foreach ($entries as $entry) {
            $url = $this->url($entry);

            if ($url === null || !$sitemap->includesEntry($entry)) {
                continue;
            }

            // A redirected URL has no business in a list of where things live,
            // for the same reason it has no business in a sitemap.
            if (Redirects::matches($url)) {
                continue;
            }

            if (count($lines) >= $limit) {
                $truncated = true;

                break;
            }

            $lines[] = LlmsDocument::line($this->title($entry), $url, $this->description($entry, $length));
        }

        $text = LlmsDocument::section(
            $section->name,
            $lines,
            $truncated,
            Url::absolute('/sitemap-' . $section->handle . '.xml'),
        );

        return $text === '' ? null : ['text' => $text, 'used' => count($lines)];
    }

    private function title(Entry $entry): string
    {
        $data = Plugin::getInstance()->getResolve()->fieldValue($entry);
        $title = trim($data->title ?? '');

        return $title !== '' ? $title : trim((string)$entry->title);
    }

    /**
     * The description for one entry, taking the cheap road on purpose.
     *
     * Not ResolveService::forElement(): that caches nothing, runs a breadcrumb
     * query per URI segment, and generates a 1200x630 social image transform
     * with immediately = true the moment an entry has a picture. Hundreds of
     * synchronous image transforms, for a text file that contains no images.
     * What is left here is the same order of preference minus everything
     * llms.txt has no use for.
     */
    private function description(Entry $entry, int $length): string
    {
        $resolve = Plugin::getInstance()->getResolve();
        $data = $resolve->fieldValue($entry);
        $typed = trim($data->description ?? '');

        if ($typed !== '') {
            return Text::summarize($typed, $length);
        }

        $handle = $resolve->fieldMapping($entry)['description'];
        $text = '';

        if ($handle !== '') {
            $text = ContentExtractor::fromField($entry, $handle, $length + 40)['text'];
        }

        if ($text === '') {
            $text = ContentExtractor::extract($entry, $length + 40)['text'];
        }

        return $text === '' ? '' : Text::summarize($text, $length);
    }

    private function url(Entry $entry): ?string
    {
        try {
            $url = $entry->getUrl();
        } catch (Throwable) {
            return null;
        }

        return $url === null ? null : Url::absolute($url);
    }
}
