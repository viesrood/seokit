<?php

declare(strict_types=1);

namespace viesrood\seokit\controllers;

use craft\web\Controller;
use viesrood\seokit\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves /llms.txt when the setting is on.
 *
 * Same rule as robots.txt on both counts: off means 404 rather than an empty
 * file, and a non-production environment gets nothing at all. A staging copy
 * handing a model a full index of itself is the same mistake as a staging copy
 * inviting crawlers in, only harder to notice.
 */
class LlmsController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->llmsTxtEnabled || !$plugin->getRobots()->isProduction()) {
            throw new NotFoundHttpException('Not found');
        }

        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()
            ->set('Content-Type', 'text/plain; charset=UTF-8')
            ->set('Cache-Control', 'public, max-age=3600');
        $response->data = $plugin->getLlms()->render();

        return $response;
    }
}
