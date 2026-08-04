<?php

declare(strict_types=1);

namespace viesrood\seokit\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use viesrood\seokit\Plugin;
use yii\web\Response;

/**
 * The settings screens, and the save action all three of them post to.
 */
class SettingsController extends Controller
{
    /**
     * Save settings from any of the screens.
     *
     * Craft's own plugins/save-plugin-settings cannot be used here, and the
     * reason is worth writing down. It persists
     * `toArray(array_keys($settings))`: only the keys that were posted, and it
     * *replaces* the settings node in project config with that. A screen
     * showing half the model therefore wipes the other half. Three screens,
     * three ways to silently lose the site's address.
     *
     * So: merge the posted subset into the current model, and save the whole
     * thing.
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('seokit:manage');

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $posted = $this->request->getBodyParam('settings');

        if (is_array($posted)) {
            $settings->setAttributes($posted, false);
        }

        // Every attribute, not just the posted ones.
        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            $this->setFailFlash(Craft::t('seokit', 'Could not save the settings.'));

            return $this->renderTemplate('seokit/cp/settings-page', ['settings' => $settings]);
        }

        $this->setSuccessFlash(Craft::t('seokit', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    public function actionIndex(): Response
    {
        $this->requirePermission('seokit:manage');

        return $this->renderTemplate('seokit/cp/settings-page', [
            'settings' => Plugin::getInstance()->getSettings(),
        ]);
    }

    public function actionRobots(): Response
    {
        $this->requirePermission('seokit:manage');

        $plugin = Plugin::getInstance();

        return $this->renderTemplate('seokit/cp/robots', [
            'settings' => $plugin->getSettings(),
            'physicalFile' => $plugin->getRobots()->physicalFile(),
            'disallowRules' => $plugin->getRobots()->disallowRules(),
            'sitemapUrl' => UrlHelper::siteUrl('sitemap.xml'),
            'sections' => $plugin->getSitemap()->sections(),
        ]);
    }
}
