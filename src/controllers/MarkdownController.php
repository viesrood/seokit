<?php

declare(strict_types=1);

namespace viesrood\seokit\controllers;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\web\Controller;
use viesrood\seokit\helpers\Url;
use viesrood\seokit\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The `.md` twin of a page: /werk/neo.md next to /werk/neo.
 *
 * The Accept header is the better mechanism and this is the fallback, for a
 * client that cannot set headers or a person pasting a link. It costs one
 * catch-all route, which is the risk here: `<path:.+>.md` is a wide net. Two
 * things keep it from swallowing anything. Craft only sees a URL when no file
 * of that name exists on disk, so real .md files keep being served as files;
 * and anything that does not resolve to an element with markdown turned on
 * 404s here rather than being interpreted further.
 */
class MarkdownController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function actionIndex(string $path): Response
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->markdownEnabled) {
            throw new NotFoundHttpException('Not found');
        }

        $element = $this->element($path);

        if ($element === null || !$plugin->getMarkdown()->includes($element)) {
            throw new NotFoundHttpException('Not found');
        }

        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()
            ->set('Content-Type', 'text/markdown; charset=UTF-8')
            // Two copies of one page is duplicate content, and the canonical
            // tag inside a markdown file is not something a crawler reads.
            // These headers are, and they say the same thing.
            ->set('X-Robots-Tag', 'noindex')
            ->set('Link', sprintf('<%s>; rel="canonical"', $this->canonical($element)))
            ->set('Vary', 'Accept');
        $response->data = $plugin->getMarkdown()->render($element);

        return $response;
    }

    private function element(string $path): ?ElementInterface
    {
        $path = trim($path, '/');

        // Control panel and action URLs never route here, but a catch-all rule
        // deserves the guard anyway: this is the one place a typo in a route
        // could hand out something unexpected.
        $cpTrigger = (string)Craft::$app->getConfig()->getGeneral()->cpTrigger;

        if ($path === '' || str_starts_with($path, 'actions/') || ($cpTrigger !== '' && str_starts_with($path, $cpTrigger . '/'))) {
            return null;
        }

        // The homepage has no URI, so its twin cannot be "/.md". `index.md` is
        // the conventional spelling and maps onto Craft's own homepage token.
        $uri = $path === 'index' ? Element::HOMEPAGE_URI : $path;
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        return Craft::$app->getElements()->getElementByUri($uri, $siteId);
    }

    private function canonical(ElementInterface $element): string
    {
        $url = $element->getUrl();

        return $url === null ? '' : Url::absolute($url);
    }
}
