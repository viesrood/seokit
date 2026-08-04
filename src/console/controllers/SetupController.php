<?php

declare(strict_types=1);

namespace viesrood\seokit\console\controllers;

use Craft;
use craft\console\Controller;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Console;
use craft\models\FieldLayoutTab;
use Throwable;
use viesrood\seokit\fields\SeoField;
use yii\console\ExitCode;

/**
 * Setting up the override field.
 *
 * SeoKit works without this: every page gets a title, a description and a
 * sharing image whether or not an editor can see them. The field is for the
 * pages where the derived answer is not the one you want, and this command
 * exists because creating a field and dragging it into a dozen field layouts
 * by hand is exactly the kind of setup people put off and then blame the
 * plugin for.
 *
 *     php craft seokit/setup/field --dry-run
 *     php craft seokit/setup/field
 *     php craft seokit/setup/field --entry-types=all
 *
 * Idempotent: run it again after adding a section and only the new layouts
 * are touched.
 */
class SetupController extends Controller
{
    /**
     * Show what would change without saving anything.
     */
    public bool $dryRun = false;

    /**
     * Which entry types to add the field to: `urls` (only ones with public
     * URLs), `all`, or `none` to just create the field.
     */
    public string $entryTypes = 'urls';

    /**
     * Handle for the field.
     */
    public string $handle = 'seo';

    /**
     * Name of the field layout tab the field is added to.
     *
     * Worth changing when another SEO plugin already owns a tab called SEO:
     * two tabs with the same name is a coin flip for the person editing.
     */
    public string $tab = 'SEO';

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'dryRun', 'entryTypes', 'handle', 'tab'];
    }

    public function actionField(): int
    {
        $fieldsService = Craft::$app->getFields();
        $field = $fieldsService->getFieldByHandle($this->handle);

        if ($field !== null && !$field instanceof SeoField) {
            $this->stderr(sprintf(
                "A field with handle \"%s\" already exists and is not a SeoKit field (%s).\nPick another handle with --handle.\n",
                $this->handle,
                $field::class,
            ), Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($field === null) {
            $this->stdout(sprintf("Creating the \"%s\" field.\n", $this->handle), Console::FG_GREEN);

            if (!$this->dryRun) {
                $field = $fieldsService->createField([
                    'type' => SeoField::class,
                    'name' => 'SEO',
                    'handle' => $this->handle,
                    'instructions' => Craft::t('seokit', 'Leave empty to use what this page already says about itself.'),
                    // The overrides duplicate content that is already indexed
                    // from the page itself, so adding them to the search
                    // index just makes every entry match twice.
                    'searchable' => false,
                ]);

                if (!$fieldsService->saveField($field)) {
                    $this->stderr("Could not save the field: " . json_encode($field->getErrors()) . "\n", Console::FG_RED);

                    return ExitCode::UNSPECIFIED_ERROR;
                }
            }
        } else {
            $this->stdout(sprintf("The \"%s\" field already exists.\n", $this->handle));
        }

        if ($this->entryTypes === 'none') {
            return ExitCode::OK;
        }

        if ($this->dryRun && $field === null) {
            $this->stdout("Dry run: the field does not exist yet, so no layouts can be listed.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        return $this->addToLayouts($field);
    }

    private function addToLayouts(SeoField $field): int
    {
        $entriesService = Craft::$app->getEntries();
        $onlyWithUrls = $this->entryTypes !== 'all';
        $added = 0;
        $skipped = 0;

        foreach ($entriesService->getAllSections() as $section) {
            if ($onlyWithUrls && !$this->hasUrls($section)) {
                continue;
            }

            foreach ($section->getEntryTypes() as $entryType) {
                $layout = $entryType->getFieldLayout();

                foreach ($layout->getCustomFields() as $existing) {
                    if ($existing instanceof SeoField) {
                        $skipped++;

                        continue 2;
                    }
                }

                $this->stdout(sprintf("  + %s / %s\n", $section->name, $entryType->name));
                $added++;

                if ($this->dryRun) {
                    continue;
                }

                try {
                    // Its own tab, at the end. Appending to an existing tab
                    // would put SEO fields in the middle of someone's
                    // carefully arranged content form, and this field is not
                    // important enough to earn that spot.
                    $tab = new FieldLayoutTab([
                        'layout' => $layout,
                        'name' => $this->tab,
                    ]);
                    $tab->setElements([new CustomField($field)]);

                    $layout->setTabs([...$layout->getTabs(), $tab]);
                    $entryType->setFieldLayout($layout);

                    if (!$entriesService->saveEntryType($entryType)) {
                        $this->stderr(sprintf(
                            "    could not save %s: %s\n",
                            $entryType->name,
                            json_encode($entryType->getErrors()),
                        ), Console::FG_RED);
                    }
                } catch (Throwable $exception) {
                    $this->stderr(sprintf("    %s: %s\n", $entryType->name, $exception->getMessage()), Console::FG_RED);
                }
            }
        }

        $this->stdout(sprintf(
            "\n%d layout(s) %s, %d already had the field.\n",
            $added,
            $this->dryRun ? 'would change' : 'changed',
            $skipped,
        ), Console::FG_GREEN);

        if ($this->dryRun) {
            $this->stdout("Dry run, nothing saved.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        // Without this the change lives in the database only, and the next
        // boot quietly reapplies the older YAML over the top of it.
        Craft::$app->getProjectConfig()->saveModifiedConfigData();

        return ExitCode::OK;
    }

    private function hasUrls(mixed $section): bool
    {
        foreach ($section->getSiteSettings() as $siteSettings) {
            if ($siteSettings->hasUrls) {
                return true;
            }
        }

        return false;
    }
}
