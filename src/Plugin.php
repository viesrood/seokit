<?php

declare(strict_types=1);

namespace viesrood\seokit;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\web\Application;
use craft\web\Response;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\TemplateEvent;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\services\Fields;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\User as CraftUser;
use craft\web\View;
use viesrood\seokit\fields\SeoField;
use viesrood\seokit\models\Settings;
use viesrood\seokit\helpers\Accept;
use viesrood\seokit\services\LlmsService;
use viesrood\seokit\services\MarkdownService;
use viesrood\seokit\services\RenderService;
use viesrood\seokit\services\ResolveService;
use viesrood\seokit\services\RobotsService;
use viesrood\seokit\services\SchemaService;
use viesrood\seokit\services\SitemapService;
use viesrood\seokit\variables\SeoKitVariable;
use yii\base\ActionEvent;
use yii\base\Event;

/**
 * SeoKit plugin.
 *
 * Meta, Open Graph, JSON-LD, sitemaps and robots.txt for Craft CMS 5, built
 * on three rules: it is correct before anyone configures it, every value
 * carries the reason it has that value, and it renders where you put the tag
 * instead of injecting itself into the response on the way out.
 *
 * That last one matters more than it sounds. A plugin that rewrites the
 * response is invisible in the template, fights with static page caches and
 * cannot be reasoned about from the markup. `{{ craft.seokit.head() }}` in
 * your layout is boring, and boring is the feature.
 *
 * @property-read ResolveService $resolve
 * @property-read RenderService $render
 * @property-read SchemaService $schema
 * @property-read SitemapService $sitemap
 * @property-read RobotsService $robots
 * @property-read LlmsService $llms
 * @property-read MarkdownService $markdown
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    public bool $hasCpSection = true;

    /**
     * @return array{components: array<string, mixed>}
     */
    public static function config(): array
    {
        return [
            'components' => [
                'resolve' => ResolveService::class,
                'render' => RenderService::class,
                'schema' => SchemaService::class,
                'sitemap' => SitemapService::class,
                'robots' => RobotsService::class,
                'llms' => LlmsService::class,
                'markdown' => MarkdownService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerFieldType();
        $this->registerVariable();
        $this->registerPermissions();
        $this->registerCpUrlRules();
        $this->registerSiteUrlRules();
        $this->registerHeadSubstitution();
        $this->registerVaryHeader();
        $this->registerMarkdownNegotiation();

        Craft::info('SeoKit plugin loaded', __METHOD__);
    }

    public function getResolve(): ResolveService
    {
        /** @var ResolveService $service */
        $service = $this->get('resolve');

        return $service;
    }

    public function getRender(): RenderService
    {
        /** @var RenderService $service */
        $service = $this->get('render');

        return $service;
    }

    public function getSchema(): SchemaService
    {
        /** @var SchemaService $service */
        $service = $this->get('schema');

        return $service;
    }

    public function getSitemap(): SitemapService
    {
        /** @var SitemapService $service */
        $service = $this->get('sitemap');

        return $service;
    }

    public function getRobots(): RobotsService
    {
        /** @var RobotsService $service */
        $service = $this->get('robots');

        return $service;
    }

    public function getLlms(): LlmsService
    {
        /** @var LlmsService $service */
        $service = $this->get('llms');

        return $service;
    }

    public function getMarkdown(): MarkdownService
    {
        /** @var MarkdownService $service */
        $service = $this->get('markdown');

        return $service;
    }

    public function getSettings(): Settings
    {
        /** @var Settings $settings */
        $settings = parent::getSettings();

        return $settings;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $user = Craft::$app->getUser();
        if (!$user instanceof CraftUser || !$user->checkPermission('seokit:manage')) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('seokit', 'SEO');
        $item['url'] = 'seokit';
        $item['subnav'] = [
            'overview' => ['label' => Craft::t('seokit', 'Overview'), 'url' => 'seokit'],
            'settings' => ['label' => Craft::t('seokit', 'Settings'), 'url' => 'seokit/settings'],
            'robots' => ['label' => Craft::t('seokit', 'Robots and sitemap'), 'url' => 'seokit/robots'],
            'ai' => ['label' => Craft::t('seokit', 'AI agents'), 'url' => 'seokit/ai'],
        ];

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('seokit/settings'));
    }

    /**
     * Namespaced the way Craft namespaces it, so the fields arrive as
     * `settings[…]` and plugins/save-plugin-settings can read them.
     */
    public function renderSettingsHtml(bool $readOnly = false): string
    {
        $view = Craft::$app->getView();

        return (string)$view->namespaceInputs(function() use ($readOnly): string {
            $html = (string)$this->settingsHtml();

            return $readOnly ? (string)Html::disableInputs(static fn(): string => $html) : $html;
        }, 'settings');
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('seokit/cp/settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerFieldType(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = SeoField::class;
            }
        );
    }

    private function registerVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function (Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('seokit', SeoKitVariable::class);
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function (RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('seokit', 'SeoKit'),
                    'permissions' => [
                        'seokit:manage' => [
                            'label' => Craft::t('seokit', 'Manage SEO settings, robots.txt and the sitemap'),
                        ],
                    ],
                ];
            }
        );
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['seokit'] = 'seokit/overview/index';
                $event->rules['seokit/settings'] = 'seokit/settings/index';
                $event->rules['seokit/robots'] = 'seokit/settings/robots';
                $event->rules['seokit/ai'] = 'seokit/settings/ai';
                $event->rules['seokit/save'] = 'seokit/settings/save';
            }
        );
    }

    /**
     * Tidy up the head markers once a page template has finished rendering,
     * and rebuild the head when a template set something after it was written.
     *
     * This is the only place SeoKit touches finished output, and it never
     * decides *where* the head goes: it only replaces the block the template
     * asked for, between the markers head() left behind.
     */
    private function registerHeadSubstitution(): void
    {
        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            function (TemplateEvent $event): void {
                if ($event->templateMode !== View::TEMPLATE_MODE_SITE) {
                    return;
                }

                $event->output = $this->getRender()->substitute($event->output);
            }
        );
    }

    /**
     * robots.txt, llms.txt and the sitemaps are plain site requests.
     *
     * All are registered unconditionally and check their own setting when
     * hit. Registering conditionally would mean the route table depends on
     * project config state at boot, and a stale cached route table is a bad
     * way to find out you turned the sitemap back on.
     */
    private function registerSiteUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['sitemap.xml'] = 'seokit/sitemap/index';
                $event->rules['sitemap-<handle:[\w\-]+>.xml'] = 'seokit/sitemap/section';
                $event->rules['robots.txt'] = 'seokit/robots/index';
                $event->rules['llms.txt'] = 'seokit/llms/index';
                $event->rules['<path:.+>.md'] = 'seokit/markdown/index';
            }
        );
    }

    /**
     * Serve markdown when the request asked for markdown.
     *
     * Registered last in init() on purpose. This handler cancels the action
     * when it fires, and a handler that cancels is only polite if everything
     * else has already had its turn.
     *
     * EVENT_BEFORE_ACTION is the moment the URL manager has finished parsing,
     * so the matched element is known, while the entry template has not run
     * yet. Anything earlier has no element; anything later has already spent
     * the work of rendering a page nobody asked for.
     */
    private function registerMarkdownNegotiation(): void
    {
        Event::on(
            Application::class,
            Application::EVENT_BEFORE_ACTION,
            function (ActionEvent $event): void {
                $request = Craft::$app->getRequest();

                if (
                    $request->getIsConsoleRequest()
                    || !$request->getIsSiteRequest()
                    || !$request->getIsGet()
                    || $request->getIsActionRequest()
                    || $request->getIsPreview()
                    || !$this->getSettings()->markdownEnabled
                ) {
                    return;
                }

                if (!Accept::prefers($request->getAcceptableContentTypes())) {
                    return;
                }

                $element = Craft::$app->getUrlManager()->getMatchedElement();

                if ($element === false || !$this->getMarkdown()->includes($element)) {
                    return;
                }

                $response = Craft::$app->getResponse();
                $response->format = Response::FORMAT_RAW;
                $response->getHeaders()
                    ->set('Content-Type', 'text/markdown; charset=UTF-8')
                    ->set('Vary', 'Accept');
                $response->data = $this->getMarkdown()->render($element);

                $event->isValid = false;
            }
        );
    }

    /**
     * `Vary: Accept` on the HTML too, not only on the markdown.
     *
     * This is the header everyone forgets, and it breaks in the least
     * observable way there is: a CDN caches whichever variant it saw first and
     * serves that to everybody. Half the visitors get markdown, and the origin
     * looks perfectly healthy the whole time.
     */
    private function registerVaryHeader(): void
    {
        Event::on(
            Response::class,
            Response::EVENT_BEFORE_SEND,
            function (Event $event): void {
                if (!$this->getSettings()->markdownEnabled) {
                    return;
                }

                $request = Craft::$app->getRequest();

                if ($request->getIsConsoleRequest() || !$request->getIsSiteRequest()) {
                    return;
                }

                /** @var Response $response */
                $response = $event->sender;
                $headers = $response->getHeaders();
                $vary = (string)$headers->get('Vary', '');

                if (!str_contains(strtolower($vary), 'accept')) {
                    $headers->set('Vary', $vary === '' ? 'Accept' : $vary . ', Accept');
                }
            }
        );
    }
}
