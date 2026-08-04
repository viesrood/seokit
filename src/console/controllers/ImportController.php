<?php

declare(strict_types=1);

namespace viesrood\seokit\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Console;
use craft\helpers\Json;
use Throwable;
use viesrood\seokit\fields\SeoField;
use viesrood\seokit\models\SeoData;
use viesrood\seokit\Plugin;
use yii\console\ExitCode;

/**
 * One-time migration from SEOmatic.
 *
 * Only the site-wide identity is imported, and that is on purpose. SEOmatic's
 * per-section title and description settings are Twig expressions
 * (`{seomatic.helper.extractTextFromField(entry.body)}` and friends), which is
 * exactly the layer SeoKit replaces with derivation. Translating them would
 * carry the configuration problem over into the plugin built to not have one.
 *
 * What this does import is the part that is genuinely data and genuinely
 * tedious to retype: the organization, the address, the social profiles and
 * the default image.
 *
 *     php craft seokit/import/seomatic --dry-run
 *     php craft seokit/import/seomatic
 */
class ImportController extends Controller
{
    /**
     * Show what would be imported without saving anything.
     */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'dryRun', 'from', 'to'];
    }

    /**
     * Field handle of SEOmatic's SeoSettings field to read from.
     */
    public string $from = 'seomatic';

    /**
     * Field handle of the SeoKit field to write to.
     */
    public string $to = 'seo';

    /**
     * Move per-entry SEOmatic overrides into the SeoKit field.
     *
     * Separate from the settings import because most sites do not need it:
     * SeoKit derives what a page says, so an override that only repeated the
     * page title is noise worth losing. What is worth keeping is the handful
     * of pages where somebody sat down and wrote a better title than the
     * entry has, and those are invisible until you go looking.
     *
     *     php craft seokit/import/entries --dry-run
     *     php craft seokit/import/entries
     *
     * Never overwrites a SeoKit value that is already filled in, so it is
     * safe to run twice.
     *
     * This saves entries. Plugins that index or purge on save will react, so
     * on a site with a paid indexing integration it is worth switching that
     * off for the duration.
     */
    public function actionEntries(): int
    {
        $fields = Craft::$app->getFields();

        if ($fields->getFieldByHandle($this->from) === null) {
            $this->stderr(sprintf("No field with handle \"%s\" found.\n", $this->from), Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $target = $fields->getFieldByHandle($this->to);

        if (!$target instanceof SeoField) {
            $this->stderr(sprintf(
                "No SeoKit field with handle \"%s\". Run seokit/setup/field first.\n",
                $this->to,
            ), Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $moved = 0;
        $skipped = 0;
        $elements = Craft::$app->getElements();

        foreach (Entry::find()->limit(null)->all() as $entry) {
            $values = $this->overridesFor($entry);

            if ($values === []) {
                continue;
            }

            $existing = $entry->getFieldLayout()?->getFieldByHandle($this->to) !== null
                ? $entry->getFieldValue($this->to)
                : null;

            if (!$existing instanceof SeoData) {
                $this->stdout(sprintf(
                    "  ? #%d %s: no \"%s\" field on this entry type\n",
                    (int)$entry->id,
                    (string)$entry->title,
                    $this->to,
                ), Console::FG_YELLOW);
                $skipped++;

                continue;
            }

            if (!$existing->isEmpty()) {
                $skipped++;

                continue;
            }

            $this->stdout(sprintf(
                "  + #%d %s: %s\n",
                (int)$entry->id,
                (string)$entry->title,
                implode(', ', array_keys($values)),
            ));
            $moved++;

            if ($this->dryRun) {
                continue;
            }

            $entry->setFieldValue($this->to, $values);

            // No validation, no propagation, no search index: this is a data
            // move, not an edit, and the content it writes is already valid
            // by construction.
            if (!$elements->saveElement($entry, false, false, false)) {
                $this->stderr(sprintf("    could not save: %s\n", json_encode($entry->getErrors())), Console::FG_RED);
            }
        }

        $this->stdout(sprintf(
            "\n%d override(s) %s, %d skipped.\n",
            $moved,
            $this->dryRun ? 'would move' : 'moved',
            $skipped,
        ), Console::FG_GREEN);

        if ($this->dryRun) {
            $this->stdout("Dry run, nothing saved.\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * The genuinely hand-written parts of an entry's SEOmatic field.
     *
     * @return array<string, mixed>
     */
    private function overridesFor(Entry $entry): array
    {
        if ($entry->getFieldLayout()?->getFieldByHandle($this->from) === null) {
            return [];
        }

        try {
            $value = $entry->getFieldValue($this->from);
        } catch (Throwable) {
            return [];
        }

        $vars = $value->metaGlobalVars ?? null;

        if ($vars === null) {
            return [];
        }

        $values = [];

        $title = $this->text($vars->seoTitle ?? null);
        if ($title !== '') {
            $values['title'] = $title;
        }

        $description = $this->text($vars->seoDescription ?? null);
        if ($description !== '') {
            $values['description'] = $description;
        }

        $canonical = $this->text($vars->canonicalUrl ?? null);
        if ($canonical !== '' && !str_starts_with($canonical, '{')) {
            $values['canonical'] = $canonical;
        }

        $image = $this->firstId($vars->seoImageIds ?? null);
        if ($image !== null) {
            $values['socialImageId'] = $image;
        }

        // SEOmatic writes the whole directive string; all that matters here is
        // whether it says this page should stay out of the index.
        $robots = strtolower($this->text($vars->robots ?? null));
        if ($robots !== '' && (str_contains($robots, 'noindex') || $robots === 'none')) {
            $values['noindex'] = true;
        }

        return $values;
    }

    public function actionSeomatic(): int
    {
        $db = Craft::$app->getDb();

        if (!$db->tableExists('{{%seomatic_metabundles}}')) {
            $this->stderr("No SEOmatic tables found in this database.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $row = (new Query())
            ->select(['metaGlobalVars', 'metaSiteVars'])
            ->from('{{%seomatic_metabundles}}')
            ->where(['sourceBundleType' => '__GLOBAL_BUNDLE__'])
            ->orderBy(['sourceSiteId' => SORT_ASC])
            ->one();

        if ($row === null) {
            $this->stderr("SEOmatic has no global bundle to import.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $site = Json::decodeIfJson((string)$row['metaSiteVars']);
        $global = Json::decodeIfJson((string)$row['metaGlobalVars']);
        $site = is_array($site) ? $site : [];
        $global = is_array($global) ? $global : [];
        $identity = is_array($site['identity'] ?? null) ? $site['identity'] : [];

        $values = array_filter([
            'siteName' => $this->text($site['siteName'] ?? null),
            'organizationName' => $this->text($identity['genericName'] ?? null),
            'organizationLegalName' => $this->text($identity['genericAlternateName'] ?? null),
            'email' => $this->text($identity['genericEmail'] ?? null),
            'telephone' => $this->text($identity['genericTelephone'] ?? null),
            'streetAddress' => $this->text($identity['genericStreetAddress'] ?? null),
            'postalCode' => $this->text($identity['genericPostalCode'] ?? null),
            'addressLocality' => $this->text($identity['genericAddressLocality'] ?? null),
            'addressRegion' => $this->text($identity['genericAddressRegion'] ?? null),
            'addressCountry' => $this->text($identity['genericAddressCountry'] ?? null),
            'defaultDescription' => $this->text($global['seoDescription'] ?? null),
            'twitterSite' => $this->handle($site['twitterHandle'] ?? null),
        ], static fn(string $value): bool => $value !== '');

        $type = $this->organizationType($identity);
        if ($type !== null) {
            $values['organizationType'] = $type;
        }

        $logo = $this->firstId($identity['genericImageIds'] ?? null);
        if ($logo !== null) {
            $values['organizationLogoId'] = $logo;
        }

        $image = $this->firstId($global['seoImageIds'] ?? null);
        if ($image !== null) {
            $values['defaultSocialImageId'] = $image;
        }

        $sameAs = $this->sameAs($site['sameAsLinks'] ?? null);
        if ($sameAs !== []) {
            $values['sameAs'] = $sameAs;
        }

        $this->stdout("Importing from SEOmatic:\n\n", Console::FG_GREEN);

        foreach ($values as $key => $value) {
            $this->stdout(sprintf("  %-24s %s\n", $key, is_array($value) ? implode(', ', $value) : (string)$value));
        }

        $this->stdout("\n");

        if ($this->dryRun) {
            $this->stdout("Dry run, nothing saved.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $settings->setAttributes($values, false);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            $this->stderr("Could not save the settings.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Saved.\n\n", Console::FG_GREEN);

        // The part no import can do for you, spelled out rather than left to
        // be discovered by a search engine.
        $this->stdout("Still to do by hand:\n", Console::FG_YELLOW);
        $this->stdout("  1. Add {{ craft.seokit.head() }} to your layout.\n");
        $this->stdout("  2. Turn off SEOmatic's rendering before going live, or every page gets two titles.\n");
        $this->stdout("  3. Check the entry type profiles on the SEO overview screen.\n");
        $this->stdout("  4. Per-entry SEOmatic overrides are not imported: SeoKit derives what they used to say.\n");

        return ExitCode::OK;
    }

    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        // SEOmatic stores unparsed Twig in these fields when a site uses
        // dynamic values. Importing "{seomatic.helper.siteUrl}" as a literal
        // description would be worse than importing nothing.
        if (str_contains($value, '{')) {
            return '';
        }

        return trim($value);
    }

    private function handle(mixed $value): string
    {
        $handle = $this->text($value);

        return $handle === '' ? '' : '@' . ltrim($handle, '@');
    }

    private function firstId(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        if (!is_array($value) || $value === []) {
            return null;
        }

        $first = reset($value);

        return is_numeric($first) ? (int)$first : null;
    }

    /**
     * @return array<int, string>
     */
    private function sameAs(mixed $links): array
    {
        if (is_string($links)) {
            $links = Json::decodeIfJson($links);
        }

        if (!is_array($links)) {
            return [];
        }

        $urls = [];

        foreach ($links as $link) {
            $url = is_array($link) ? ($link['url'] ?? '') : $link;

            if (is_string($url) && trim($url) !== '') {
                $urls[] = trim($url);
            }
        }

        return $urls;
    }

    /**
     * @param array<string, mixed> $identity
     */
    private function organizationType(array $identity): ?string
    {
        $computed = $identity['computedType'] ?? $identity['siteType'] ?? null;

        if (!is_string($computed)) {
            return null;
        }

        return match ($computed) {
            'Person' => 'Person',
            'LocalBusiness' => 'LocalBusiness',
            'ProfessionalService' => 'ProfessionalService',
            default => 'Organization',
        };
    }
}
