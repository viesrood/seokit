<?php

declare(strict_types=1);

namespace viesrood\seokit\controllers;

use craft\helpers\UrlHelper;
use craft\web\Controller;
use viesrood\seokit\Plugin;
use yii\web\Response;

/**
 * The two settings screens. Both post to Craft's own
 * plugins/save-plugin-settings, which merges what it is given, so a screen
 * that shows half the model does not wipe the other half.
 */
class SettingsController extends Controller
{
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
