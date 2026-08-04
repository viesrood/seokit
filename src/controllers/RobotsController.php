<?php

declare(strict_types=1);

namespace viesrood\seokit\controllers;

use craft\web\Controller;
use viesrood\seokit\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves /robots.txt when the setting is on.
 *
 * When it is off this 404s rather than emitting an empty file: no robots.txt
 * at all means "crawl everything", which is the correct default, while an
 * empty one served with a 200 means the same thing but looks like a mistake.
 */
class RobotsController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->robotsTxtEnabled) {
            throw new NotFoundHttpException('Not found');
        }

        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->data = $plugin->getRobots()->render();

        return $response;
    }
}
