<?php

declare(strict_types=1);

namespace viesrood\seokit\controllers;

use craft\web\Controller;
use viesrood\seokit\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves /sitemap.xml and /sitemap-<section>.xml.
 */
class SitemapController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function actionIndex(): Response
    {
        $this->requireSitemapEnabled();

        return $this->xml(Plugin::getInstance()->getSitemap()->index());
    }

    public function actionSection(string $handle): Response
    {
        $this->requireSitemapEnabled();

        $xml = Plugin::getInstance()->getSitemap()->section($handle);

        if ($xml === null) {
            throw new NotFoundHttpException('Sitemap not found');
        }

        return $this->xml($xml);
    }

    private function requireSitemapEnabled(): void
    {
        if (!Plugin::getInstance()->getSettings()->sitemapEnabled) {
            throw new NotFoundHttpException('Sitemap not found');
        }
    }

    private function xml(string $body): Response
    {
        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'application/xml; charset=UTF-8');

        // A crawler that comes back tomorrow should not be handed a cached
        // copy from a CDN that never saw today's publication.
        $response->getHeaders()->set('Cache-Control', 'public, max-age=3600');

        $response->data = $body;

        return $response;
    }
}
