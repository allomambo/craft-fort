<?php

namespace allomambo\fort;

use Craft;
use allomambo\fort\helpers\ConfigOverrideHelper;
use allomambo\fort\helpers\FortClientIp;
use allomambo\fort\helpers\RuntimePresenter;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\controllers\UsersController;
use craft\elements\User;
use craft\events\LoginFailureEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\i18n\PhpMessageSource;
use craft\web\Application as WebApplication;
use craft\web\Controller;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\web\Response as YiiResponse;

/**
 * Fort — rate limiting and security event monitoring.
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.6.0';

    public bool $hasCpSettings = true;

    public bool $hasCpSection = true;

    public bool $hasReadOnlyCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'rateLimiter' => services\RateLimitService::class,
                'securityEvents' => services\SecurityEventService::class,
                'alerts' => services\AlertService::class,
                'notifications' => services\NotificationService::class,
                'runtimeSettings' => services\RuntimeSettingsService::class,
                'ipBlocks' => services\IpBlockService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Register translations from package root. craft\base\Plugin uses getBasePath().'/translations';
        // when basePath is wrong (e.g. points at src/), French and other locales never load.
        $translationsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'translations';
        if (is_dir($translationsPath)) {
            Craft::$app->getI18n()->translations[$this->id] = [
                'class' => PhpMessageSource::class,
                'sourceLanguage' => $this->sourceLanguage,
                'basePath' => $translationsPath,
                'forceTranslation' => true,
                'allowOverrides' => true,
            ];
        }

        // Ensure CP templates resolve even when getBasePath() points somewhere without a templates/ dir (e.g. some Docker/vendor layouts).
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $e) {
                $plugin = self::getInstance();
                if ($plugin === null) {
                    return;
                }
                $dir = self::getTemplatesRoot();
                if (is_dir($dir)) {
                    $e->roots[strtolower($plugin->id)] = $dir;
                }
            }
        );

        // Without explicit rules, CP template resolution can render plugin templates without hitting controllers (Commerce-style).
        // Overview stays at `fort/dashboard`; other subpages use `fort/settings`, `fort/blocked`, etc. Legacy `fort/dashboard/*`
        // routes remain so old links keep working.
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event) {
                $event->rules = [
                    'fort/clear-events' => 'fort/dashboard/clear-events',
                    'fort/clear-runtime' => 'fort/dashboard/clear-runtime',
                    'fort/save-runtime' => 'fort/dashboard/save-runtime',
                    'fort/add-block' => 'fort/dashboard/add-block',
                    'fort/unblock' => 'fort/dashboard/unblock',
                    'fort/save-notes' => 'fort/dashboard/save-notes',
                    'fort/runtime-modal' => 'fort/dashboard/runtime-modal',
                    'fort/block-ip-modal' => 'fort/dashboard/block-ip-modal',
                    'fort/settings' => 'fort/dashboard/settings',
                    'fort/blocked' => 'fort/dashboard/blocked',
                    'fort/alerts' => 'fort/dashboard/alerts',
                    'fort/events' => 'fort/dashboard/events',
                    'fort/dashboard/clear-events' => 'fort/dashboard/clear-events',
                    'fort/dashboard/clear-runtime' => 'fort/dashboard/clear-runtime',
                    'fort/dashboard/save-runtime' => 'fort/dashboard/save-runtime',
                    'fort/dashboard/add-block' => 'fort/dashboard/add-block',
                    'fort/dashboard/unblock' => 'fort/dashboard/unblock',
                    'fort/dashboard/save-notes' => 'fort/dashboard/save-notes',
                    'fort/dashboard/runtime-modal' => 'fort/dashboard/runtime-modal',
                    'fort/dashboard/block-ip-modal' => 'fort/dashboard/block-ip-modal',
                    'fort/dashboard/settings' => 'fort/dashboard/settings',
                    'fort/dashboard/blocked' => 'fort/dashboard/blocked',
                    'fort/dashboard/alerts' => 'fort/dashboard/alerts',
                    'fort/dashboard/events' => 'fort/dashboard/events',
                    'fort/dashboard' => 'fort/dashboard/index',
                    'fort' => 'fort/dashboard/cp-root',
                ] + $event->rules;
            }
        );

        if (Craft::$app instanceof WebApplication && !Craft::$app->getRequest()->getIsConsoleRequest()) {
            Event::on(
                \yii\base\Application::class,
                \yii\base\Application::EVENT_BEFORE_REQUEST,
                function () {
                    $plugin = self::getInstance();
                    $plugin->ipBlocks->enforceRequest();
                    $plugin->rateLimiter->enforce();
                }
            );

            Event::on(
                \yii\base\Application::class,
                \yii\base\Application::EVENT_AFTER_REQUEST,
                function () {
                    if (Craft::$app->getRequest()->getIsConsoleRequest()) {
                        return;
                    }

                    $plugin = self::getInstance();
                    if ($plugin === null || !Craft::$app->getPlugins()->isPluginEnabled($plugin->id)) {
                        return;
                    }

                    /** @var models\Settings $settings */
                    $settings = $plugin->getSettings();

                    if ($settings->digestSendOnActivity) {
                        $plugin->notifications->maybeSendDigestsIfDue();
                    }

                    if ($settings->autoSweepExpiredIpBlocks) {
                        $plugin->ipBlocks->maybeSweepExpiredBlocks();
                    }
                }
            );

            $this->registerLoginSecurityListeners();
        }
    }

    /**
     * Record login success/failure for all Craft login flows (CP, site, passkey path via controller).
     * Previously only the custom userextended API wrapper called {@see SecurityEventService::recordLoginAttempt()}.
     */
    private function registerLoginSecurityListeners(): void
    {
        Event::on(
            UsersController::class,
            UsersController::EVENT_LOGIN_FAILURE,
            function (LoginFailureEvent $event) {
                $plugin = self::getInstance();
                if ($plugin === null || !Craft::$app->getPlugins()->isPluginEnabled($plugin->id)) {
                    return;
                }

                $request = Craft::$app->getRequest();
                $attemptedLogin = null;
                if ($event->user !== null) {
                    $attemptedLogin = $event->user->email ?: $event->user->username ?: null;
                }
                if ($attemptedLogin === null || $attemptedLogin === '') {
                    $body = $request->getBodyParams();
                    $attemptedLogin = $body['loginName'] ?? $body['username'] ?? $body['email'] ?? null;
                }
                if (is_string($attemptedLogin)) {
                    // Strip C0 control bytes and DEL to prevent log / digest / event-viewer CR/LF injection.
                    // Keeps UTF-8 printables (accents, CJK, emoji). Fall back to the non-UTF-8 variant when
                    // preg_replace returns null (invalid UTF-8 input).
                    $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $attemptedLogin);
                    if ($clean === null) {
                        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $attemptedLogin) ?? '';
                    }
                    $attemptedLogin = StringHelper::truncate(trim((string) $clean), 128);
                    if ($attemptedLogin === '') {
                        $attemptedLogin = null;
                    }
                } else {
                    $attemptedLogin = null;
                }

                $meta = array_filter([
                    'authError' => $event->authError,
                    'userId' => $event->user?->id,
                    'attemptedLogin' => $attemptedLogin,
                ], static fn($v) => $v !== null && $v !== '');

                $rawIp = FortClientIp::getEffective($request) ?: '0.0.0.0';

                $plugin->securityEvents->recordLoginAttempt(
                    false,
                    $rawIp,
                    $request->getPathInfo() ?: null,
                    $meta
                );
            }
        );
    }

    public function beforeSaveSettings(): bool
    {
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            /** @var models\Settings $settings */
            $settings = $this->getSettings();
            $posted = Craft::$app->getRequest()->getBodyParam('settings', []);

            if (isset($posted['excludedIpsText']) && is_string($posted['excludedIpsText'])) {
                $lines = preg_split('/\r\n|\r|\n/', $posted['excludedIpsText']);
                $settings->excludedIps = array_values(array_filter(array_map('trim', $lines)));
            }

            if (array_key_exists('maintainerUserIds', $posted)) {
                $raw = $posted['maintainerUserIds'];
                if ($raw === '' || $raw === null) {
                    $settings->maintainerUserIds = [];
                } elseif (is_array($raw)) {
                    $settings->maintainerUserIds = array_values(array_unique(array_map(
                        'intval',
                        array_filter($raw, static fn($v) => $v !== '' && $v !== null)
                    )));
                } elseif (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $settings->maintainerUserIds = array_values(array_unique(array_map('intval', $decoded)));
                    }
                }
            }
        }

        return parent::beforeSaveSettings();
    }

    public function getSettingsResponse(): mixed
    {
        return $this->redirectCpPluginSettingsToFortSection();
    }

    public function getReadOnlySettingsResponse(): mixed
    {
        return $this->redirectCpPluginSettingsToFortSection();
    }

    /**
     * Settings → Plugins (and the Fort icon on the Settings index) use `settings/plugins/fort`.
     * Send users to the Fort CP subnav settings page so breadcrumbs and sidebar stay in Fort.
     *
     * Do not forward the whole query string: Craft often sets `p` to the CP path when routing uses
     * `index.php?p=…`. Passing `p=settings/plugins/fort` onto `fort/settings` breaks routing
     * (redirect loops / NSURLError -310). Only preserve params Fort actually uses.
     */
    private function redirectCpPluginSettingsToFortSection(): YiiResponse
    {
        $controller = Craft::$app->controller;
        $request = Craft::$app->getRequest();
        $params = array_filter([
            'site' => $request->getQueryParam('site'),
            'tab' => $request->getQueryParam('tab'),
        ], static fn($v) => $v !== null && $v !== '');

        $url = UrlHelper::cpUrl('fort/settings', $params);

        return $controller->redirect($url);
    }

    /**
     * @param 'cp-section'|'global-settings' $context
     */
    public function renderPluginSettings(Controller $controller, bool $readOnly, string $context = 'global-settings'): YiiResponse
    {
        $view = Craft::$app->getView();
        $settingsHtml = $view->namespaceInputs(function () use ($readOnly) {
            if ($readOnly) {
                return (string) Html::disableInputs(fn() => $this->settingsHtml());
            }

            return (string) $this->settingsHtml();
        }, 'settings');

        /** @var models\Settings $settings */
        $settings = $this->getSettings();
        $runtime = $this->runtimeSettings->getRow();
        $tunables = RuntimePresenter::tunables($runtime, $settings, $this->runtimeSettings);
        $fortActiveRuntimeOverrides = array_values(array_map(
            static fn($t) => $t['label'],
            array_filter($tunables, static fn($t) => $t['isRuntimeOverridden'])
        ));

        $vars = [
            'plugin' => $this,
            'settingsHtml' => $settingsHtml,
            'readOnly' => $readOnly,
            'selectedTab' => $this->resolveSettingsTab(),
            'fortSettingsContext' => $context,
            'fortActiveRuntimeOverrides' => $fortActiveRuntimeOverrides,
        ];

        if ($context === 'cp-section') {
            $vars['selectedSubnavItem'] = 'settings';
        }

        return $controller->renderTemplate('fort/cp/plugin-settings', $vars);
    }

    private function resolveSettingsTab(): string
    {
        $allowed = ['auth', 'rateLimit', 'ipBlocking', 'notifications', 'retention', 'config'];
        $tab = Craft::$app->getRequest()->getQueryParam('tab');

        return is_string($tab) && in_array($tab, $allowed, true) ? $tab : 'auth';
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        if ($item === null) {
            return null;
        }
        $item['label'] = Craft::t('fort', 'Fort');
        // Parent URL must be a prefix of every subnav URL (Craft CP). Use `fort` so `fort/dashboard`, `fort/settings`, … work.
        $item['url'] = 'fort';
        $item['icon'] = 'shield';
        $item['subnav'] = [
            'dashboard' => [
                'label' => Craft::t('fort', 'Dashboard'),
                'url' => 'fort/dashboard',
            ],
            'blocked' => [
                'label' => Craft::t('fort', 'Blocked IPs'),
                'url' => 'fort/blocked',
            ],
            'alerts' => [
                'label' => Craft::t('fort', 'Alerts'),
                'url' => 'fort/alerts',
            ],
            'events' => [
                'label' => Craft::t('fort', 'Events'),
                'url' => 'fort/events',
            ],
            'settings' => [
                'label' => Craft::t('app', 'Settings'),
                'url' => 'fort/settings',
            ],
        ];

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new models\Settings();
    }

    /**
     * Absolute path to the package's `templates/` directory.
     */
    public static function getTemplatesRoot(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'templates';
    }

    /**
     * @throws InvalidConfigException
     */
    protected function settingsHtml(): ?string
    {
        $view = Craft::$app->getView();

        /** @var models\Settings $settings */
        $settings = $this->getSettings();

        $ids = $settings->maintainerUserIds;
        $maintainerUsers = [];
        if (!empty($ids)) {
            $maintainerUsers = User::find()->id($ids)->status(null)->all();
        }

        $examplePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'config-example.php.txt';
        $configExample = is_readable($examplePath) ? (string) file_get_contents($examplePath) : '';

        $runtime = $this->runtimeSettings->getRow();
        $tunables = RuntimePresenter::tunables($runtime, $settings, $this->runtimeSettings);
        $fortActiveRuntimeOverrides = array_values(array_map(
            static fn($t) => $t['label'],
            array_filter($tunables, static fn($t) => $t['isRuntimeOverridden'])
        ));

        $requestIp = Craft::$app->getRequest()->getUserIP() ?: '';

        $variables = [
            'settings' => $settings,
            'selectedTab' => $this->resolveSettingsTab(),
            'overrideKeys' => ConfigOverrideHelper::fileDefinedKeys(),
            'maintainerUsers' => $maintainerUsers,
            'configExample' => $configExample,
            'fortActiveRuntimeOverrides' => $fortActiveRuntimeOverrides,
            'currentUserIp' => $requestIp,
        ];

        return $view->renderTemplate('fort/settings', $variables, View::TEMPLATE_MODE_CP);
    }
}
