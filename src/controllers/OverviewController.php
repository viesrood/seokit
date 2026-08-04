<?php

declare(strict_types=1);

namespace viesrood\seokit\controllers;

use Craft;
use craft\elements\Entry;
use craft\fields\Assets;
use craft\fields\Color;
use craft\fields\Date;
use craft\fields\Lightswitch;
use craft\fields\Money;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\Time;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\Section;
use craft\web\Controller;
use viesrood\seokit\fields\SeoField;
use viesrood\seokit\Plugin;
use viesrood\seokit\services\ResolveService;
use yii\web\Response;

/**
 * The overview screen: is this thing actually switched on.
 *
 * Every check here answers a question that otherwise gets discovered weeks
 * later on a site that has quietly been serving nothing. Is head() in the
 * layout, is the field in any field layout, is another SEO plugin also
 * writing tags, does a physical robots.txt override the one in settings.
 */
class OverviewController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePermission('seokit:manage');

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('seokit/cp/overview', [
            'settings' => $settings,
            'checks' => $this->checks(),
            'entryTypes' => $this->entryTypes(),
            'profiles' => ResolveService::profiles(),
            'sitemapUrl' => UrlHelper::siteUrl('sitemap.xml'),
            'sections' => $plugin->getSitemap()->sections(),
        ]);
    }

    /**
     * @return array<int, array{status: string, label: string, detail: string}>
     */
    private function checks(): array
    {
        $plugin = Plugin::getInstance();
        $checks = [];

        // The field being in no field layout at all is the single most common
        // reason a fresh install "does not work": everything is derived and
        // nobody can override anything.
        $layouts = 0;
        $total = 0;

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            foreach ($section->getEntryTypes() as $entryType) {
                $total++;

                foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
                    if ($field instanceof SeoField) {
                        $layouts++;

                        break;
                    }
                }
            }
        }

        $checks[] = $layouts > 0
            ? [
                'status' => 'ok',
                'label' => Craft::t('seokit', 'Editors can override: {count} of {total} entry types', [
                    'count' => $layouts,
                    'total' => $total,
                ]),
                'detail' => Craft::t('seokit', 'The rest still get a derived title, description and sharing image; nobody can adjust them by hand.'),
            ]
            : [
                'status' => 'info',
                'label' => Craft::t('seokit', 'No SEO field in any layout yet'),
                'detail' => Craft::t('seokit', 'Pages still get a correct title, description and social card. Run `php craft seokit/setup/field` to let editors override that.'),
            ];

        $checks[] = $plugin->getSettings()->sitemapEnabled
            ? [
                'status' => 'ok',
                'label' => Craft::t('seokit', 'Sitemap is being served'),
                'detail' => UrlHelper::siteUrl('sitemap.xml'),
            ]
            : [
                'status' => 'warning',
                'label' => Craft::t('seokit', 'Sitemap is off'),
                'detail' => Craft::t('seokit', 'Search engines fall back to crawling links only.'),
            ];

        $physical = $plugin->getRobots()->physicalFile();

        if ($physical !== null && $plugin->getSettings()->robotsTxtEnabled) {
            $checks[] = [
                'status' => 'warning',
                'label' => Craft::t('seokit', 'robots.txt is a file on disk'),
                'detail' => Craft::t('seokit', 'That file wins over the text in these settings, so editing it here changes nothing: {path}', ['path' => $physical]),
            ];
        }

        // Two plugins writing meta tags is not a conflict Craft can detect,
        // and the symptom (two titles, two canonicals) is invisible until
        // someone reads the source.
        foreach (['seomatic', 'sprout-seo', 'seo'] as $handle) {
            if (Craft::$app->getPlugins()->isPluginEnabled($handle)) {
                $checks[] = [
                    'status' => 'warning',
                    'label' => Craft::t('seokit', 'Another SEO plugin is enabled: {handle}', ['handle' => $handle]),
                    'detail' => Craft::t('seokit', 'Two plugins writing meta tags means duplicate titles and canonicals. Switch its rendering off before going live with SeoKit.'),
                ];
            }
        }

        return $checks;
    }

    /**
     * Every entry type with its resolved profile, so the guesses are visible
     * and correctable in one table instead of one entry at a time.
     *
     * Keyed by UID, not listed per section. Entry types are global in Craft 5
     * and can be reused across sections, and a second row for the same UID
     * would post the same input names as the first: whichever row sat lower
     * in the table would silently overwrite the choice made in the one above
     * it. One entry type, one row, all the sections it is used in named.
     *
     * @return array<int, array<string, mixed>>
     */
    private function entryTypes(): array
    {
        $configured = Plugin::getInstance()->getSettings()->entryTypes;
        $resolve = Plugin::getInstance()->getResolve();
        $rows = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            foreach ($section->getEntryTypes() as $entryType) {
                if (isset($rows[$entryType->uid])) {
                    $rows[$entryType->uid]['sections'][] = $section->name;
                    $rows[$entryType->uid]['hasUrls'] = $rows[$entryType->uid]['hasUrls']
                        || $this->hasUrls($section);

                    continue;
                }

                $sample = Entry::find()
                    ->typeId($entryType->id)
                    ->uri(':notempty:')
                    ->one();

                $guessed = $sample instanceof Entry
                    ? $resolve->profileFor($sample)
                    : ['profile' => 'webpage', 'reason' => '', 'sitemap' => true];

                $rows[$entryType->uid] = [
                    'uid' => $entryType->uid,
                    'sections' => [$section->name],
                    'name' => $entryType->name,
                    'handle' => $entryType->handle,
                    'profile' => $configured[$entryType->uid]['profile'] ?? 'auto',
                    'guessed' => $guessed['profile'],
                    'sitemap' => $configured[$entryType->uid]['sitemap'] ?? true,
                    'indexable' => $configured[$entryType->uid]['indexable'] ?? true,
                    'titleField' => $configured[$entryType->uid]['titleField'] ?? '',
                    'descriptionField' => $configured[$entryType->uid]['descriptionField'] ?? '',
                    'imageField' => $configured[$entryType->uid]['imageField'] ?? '',
                    'fieldOptions' => $this->fieldOptions($entryType),
                    'hasUrls' => $this->hasUrls($section),
                    'sample' => $sample,
                ];
            }
        }

        return array_values($rows);
    }

    private function hasUrls(Section $section): bool
    {
        foreach ($section->getSiteSettings() as $siteSettings) {
            if ($siteSettings->hasUrls) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which fields of an entry type can be offered for each purpose.
     *
     * Filtered rather than listed wholesale: a dropdown that offers a
     * lightswitch as a source for the meta description is a dropdown that
     * makes people distrust the whole screen. Text-ish fields for the title
     * and description, asset fields for the image, and never SeoKit's own
     * field, which is the override and not a source.
     *
     * @return array{title: array<int, array{label: string, value: string}>, description: array<int, array{label: string, value: string}>, image: array<int, array{label: string, value: string}>}
     */
    private function fieldOptions(EntryType $entryType): array
    {
        $auto = Craft::t('seokit', 'Automatic');

        $options = [
            'title' => [['label' => $auto . ' (' . Craft::t('seokit', 'entry title') . ')', 'value' => '']],
            'description' => [['label' => $auto, 'value' => '']],
            'image' => [['label' => $auto, 'value' => '']],
        ];

        // Field types that hold no readable prose. Everything else (plain
        // text, CKEditor, Matrix, Super Table, dropdowns) is fair game,
        // because a block field is often exactly where the intro lives.
        $notProse = [
            Lightswitch::class,
            Date::class,
            Time::class,
            Number::class,
            Money::class,
            Color::class,
            Assets::class,
        ];

        foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
            $handle = (string)$field->handle;

            if ($handle === '' || $field instanceof SeoField) {
                continue;
            }

            $label = sprintf('%s (%s)', $field->name, $handle);

            if ($field instanceof Assets) {
                $options['image'][] = ['label' => $label, 'value' => $handle];

                continue;
            }

            foreach ($notProse as $class) {
                if ($field instanceof $class) {
                    continue 2;
                }
            }

            $options['description'][] = ['label' => $label, 'value' => $handle];

            // A title has to be one line, so only genuinely flat fields.
            if ($field instanceof PlainText) {
                $options['title'][] = ['label' => $label, 'value' => $handle];
            }
        }

        return $options;
    }
}
