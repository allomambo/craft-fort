<?php

namespace allomambo\fort\controllers;

use allomambo\fort\helpers\EventDisplayHelper;
use allomambo\fort\helpers\FortCpDatetime;
use allomambo\fort\helpers\IpHelper;
use allomambo\fort\helpers\RuntimePresenter;
use allomambo\fort\Plugin;
use Craft;
use craft\elements\User;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use yii\web\Response;

class DashboardController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission('accessPlugin-fort');

        return parent::beforeAction($action);
    }

    /**
     * Sidebar “Fort” uses parent URL `fort`; redirect to the overview route so the address bar shows `fort/dashboard`.
     */
    public function actionCpRoot(): Response
    {
        return $this->redirect(UrlHelper::cpUrl('fort/dashboard', $this->tabBaseParams()));
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $tab = $request->getQueryParam('tab');
        if (is_string($tab) && in_array($tab, ['blocked', 'alerts', 'events'], true)) {
            $path = match ($tab) {
                'blocked' => 'fort/blocked',
                'alerts' => 'fort/alerts',
                'events' => 'fort/events',
                default => 'fort/dashboard',
            };
            $params = array_filter([
                'site' => $request->getQueryParam('site'),
            ], static fn($v) => $v !== null && $v !== '');

            return $this->redirect(UrlHelper::cpUrl($path, $params));
        }

        return $this->renderOverview();
    }

    public function actionBlocked(): Response
    {
        return $this->renderBlocked();
    }

    public function actionAlerts(): Response
    {
        return $this->renderAlerts();
    }

    public function actionEvents(): Response
    {
        return $this->renderEvents();
    }

    public function actionSettings(): Response
    {
        $this->requireCpRequest();
        // Require admin, but allow viewing when allowAdminChanges is false (read-only UI).
        // Must be PHP: Craft 4 Twig `{% requireAdmin %}` rejects the `false` argument added in Craft 5.
        $this->requireAdmin(false);

        return Plugin::getInstance()->renderPluginSettings($this, $this->cpPluginSettingsReadOnly(), 'cp-section');
    }

    /**
     * Match Craft’s plugin settings behaviour: {@see BasePlugin::hasReadOnlyCpSettings} uses read-only UI for non-admins.
     */
    private function cpPluginSettingsReadOnly(): bool
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            return true;
        }

        $plugin = Plugin::getInstance();
        if (!$plugin->hasReadOnlyCpSettings) {
            return false;
        }

        return !Craft::$app->getUser()->getIsAdmin();
    }

    private function tabBaseParams(): array
    {
        $request = Craft::$app->getRequest();

        return array_filter([
            'site' => $request->getQueryParam('site'),
        ], static fn($v) => $v !== null && $v !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function fortActionUrls(): array
    {
        $base = $this->tabBaseParams();

        return [
            'fortCraftIs5' => Plugin::isCraft5(),
            'fortSaveRuntimeUrl' => UrlHelper::cpUrl('fort/save-runtime', $base),
            'fortAddBlockUrl' => UrlHelper::cpUrl('fort/add-block', $base),
            'fortUnblockUrl' => UrlHelper::cpUrl('fort/unblock', $base),
            'fortSaveNotesUrl' => UrlHelper::cpUrl('fort/save-notes', $base),
            'fortClearRuntimeUrl' => UrlHelper::cpUrl('fort/clear-runtime', $base),
            // Keep plugin/controller/action path; Craft.CpModal + multisite need full cpUrl (short `fort/runtime-modal` can fail to load).
            'fortRuntimeModalUrl' => UrlHelper::cpUrl('fort/dashboard/runtime-modal', $base),
            'fortBlockIpModalUrl' => UrlHelper::cpUrl('fort/dashboard/block-ip-modal', $base),
            'fortAppTimezone' => Craft::$app->getTimeZone(),
            'fortAllBlockedUrl' => UrlHelper::cpUrl('fort/blocked', $base),
            'fortAllAlertsUrl' => UrlHelper::cpUrl('fort/alerts', $base),
            'fortAllEventsUrl' => UrlHelper::cpUrl('fort/events', $base),
            'fortAlertsLoginUrl' => UrlHelper::cpUrl('fort/alerts', array_merge($base, ['filter' => 'login_threshold'])),
            'fortAlertsRateUrl' => UrlHelper::cpUrl('fort/alerts', array_merge($base, ['filter' => 'http_rate_limited'])),
            'fortEventsBlockedUrl' => UrlHelper::cpUrl('fort/events', array_merge($base, ['filter' => 'ip_blocked'])),
        ];
    }

    private function renderOverview(): Response
    {
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $since = (new \DateTimeImmutable())->modify('-24 hours');
        $counts = $plugin->securityEvents->countsSince($since);
        $dailyCounts = $plugin->securityEvents->dailyCounts(30);
        $runtime = $plugin->runtimeSettings->getRow();
        $blockedLatest = $plugin->ipBlocks->listBlockedRowsForCp(5);
        $latestAlerts = $this->fortAlertsWithCpDatetime($plugin->alerts->recentForCp(10));
        /** @var \allomambo\fort\models\Settings $settings */
        $settings = $plugin->getSettings();
        $requestIpNormalized = $plugin->ipBlocks->normalizeClientIp($request->getUserIP() ?: '0.0.0.0');
        $fortCurrentIpExcluded = IpHelper::matchesExcluded($requestIpNormalized, $settings->excludedIps);

        $fortTunables = RuntimePresenter::tunables($runtime, $settings, $plugin->runtimeSettings);
        $fortFeatureGates = RuntimePresenter::featureGates($settings);
        $fortHasAnyRuntimeOverride = !empty(array_filter($fortTunables, static fn($t) => $t['isRuntimeOverridden']));

        return $this->renderTemplate('fort/dashboard/index', array_merge([
            'fortCounts24h' => $counts,
            'fortDailyCounts' => $dailyCounts,
            'fortRuntime' => $runtime,
            'fortLatestBlockedRows' => $blockedLatest,
            'fortLatestAlertRows' => $latestAlerts,
            'fortTunables' => $fortTunables,
            'fortFeatureGates' => $fortFeatureGates,
            'fortHasAnyRuntimeOverride' => $fortHasAnyRuntimeOverride,
            'fortRequestClientIp' => $requestIpNormalized,
            'fortCurrentIpExcluded' => $fortCurrentIpExcluded,
            'title' => Craft::t('fort', 'Dashboard'),
            'docTitle' => Craft::t('fort', 'Dashboard') . ' - ' . Craft::t('fort', 'Fort'),
            // Craft 4 crumbs.twig requires every crumb to have a url (Craft 5 allows omitting it).
            'crumbs' => [
                ['label' => Craft::t('fort', 'Fort'), 'url' => UrlHelper::cpUrl('fort/dashboard', $this->tabBaseParams())],
                ['label' => Craft::t('fort', 'Dashboard'), 'url' => UrlHelper::cpUrl('fort/dashboard', $this->tabBaseParams())],
            ],
            'selectedSubnavItem' => 'dashboard',
        ], $this->fortActionUrls()), View::TEMPLATE_MODE_CP);
    }

    private function renderBlocked(): Response
    {
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        /** @var \allomambo\fort\models\Settings $settings */
        $settings = $plugin->getSettings();
        $requestIpNormalized = $plugin->ipBlocks->normalizeClientIp($request->getUserIP() ?: '0.0.0.0');
        $fortCurrentIpExcluded = IpHelper::matchesExcluded($requestIpNormalized, $settings->excludedIps);
        $blockedRows = $plugin->ipBlocks->listBlockedRowsForCp(500);

        return $this->renderTemplate('fort/dashboard/blocked', array_merge([
            'fortBlockedRows' => $blockedRows,
            'fortRequestClientIp' => $requestIpNormalized,
            'fortCurrentIpExcluded' => $fortCurrentIpExcluded,
            'title' => Craft::t('fort', 'Blocked IPs'),
            'docTitle' => Craft::t('fort', 'Blocked IPs') . ' - ' . Craft::t('fort', 'Fort'),
            'crumbs' => [
                ['label' => Craft::t('fort', 'Fort'), 'url' => UrlHelper::cpUrl('fort/dashboard', $this->tabBaseParams())],
                ['label' => Craft::t('fort', 'Blocked IPs'), 'url' => UrlHelper::cpUrl('fort/blocked', $this->tabBaseParams())],
            ],
            'selectedSubnavItem' => 'blocked',
        ], $this->fortActionUrls()), View::TEMPLATE_MODE_CP);
    }

    private function renderAlerts(): Response
    {
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        /** @var \allomambo\fort\models\Settings $settings */
        $settings = $plugin->getSettings();
        $requestIpNormalized = $plugin->ipBlocks->normalizeClientIp($request->getUserIP() ?: '0.0.0.0');
        $fortCurrentIpExcluded = IpHelper::matchesExcluded($requestIpNormalized, $settings->excludedIps);
        $alertRows = $this->fortAlertsWithCpDatetime($plugin->alerts->recentForCp(200));
        $effectiveLoginThreshold = $plugin->runtimeSettings->getFailedLoginThreshold($settings);
        $effectiveLoginWindowMinutes = $plugin->runtimeSettings->getFailedLoginWindowMinutes($settings);

        return $this->renderTemplate('fort/dashboard/alerts', array_merge([
            'fortAlertRows' => $alertRows,
            'fortRequestClientIp' => $requestIpNormalized,
            'fortCurrentIpExcluded' => $fortCurrentIpExcluded,
            'fortEffectiveLoginThreshold' => $effectiveLoginThreshold,
            'fortEffectiveLoginWindowMinutes' => $effectiveLoginWindowMinutes,
            'title' => Craft::t('fort', 'Alerts'),
            'docTitle' => Craft::t('fort', 'Alerts') . ' - ' . Craft::t('fort', 'Fort'),
            'crumbs' => [
                ['label' => Craft::t('fort', 'Fort'), 'url' => UrlHelper::cpUrl('fort/dashboard', $this->tabBaseParams())],
                ['label' => Craft::t('fort', 'Alerts'), 'url' => UrlHelper::cpUrl('fort/alerts', $this->tabBaseParams())],
            ],
            'selectedSubnavItem' => 'alerts',
        ], $this->fortActionUrls()), View::TEMPLATE_MODE_CP);
    }

    private function renderEvents(): Response
    {
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $eventsFull = $this->fortEventsWithCpDatetime($plugin->securityEvents->recentEvents(100));
        /** @var \allomambo\fort\models\Settings $settings */
        $settings = $plugin->getSettings();
        $requestIpNormalized = $plugin->ipBlocks->normalizeClientIp($request->getUserIP() ?: '0.0.0.0');
        $fortCurrentIpExcluded = IpHelper::matchesExcluded($requestIpNormalized, $settings->excludedIps);
        $effectiveLoginThreshold = $plugin->runtimeSettings->getFailedLoginThreshold($settings);
        $effectiveLoginWindowMinutes = $plugin->runtimeSettings->getFailedLoginWindowMinutes($settings);

        return $this->renderTemplate('fort/dashboard/events', array_merge([
            'fortRecentEvents' => $eventsFull,
            'fortRequestClientIp' => $requestIpNormalized,
            'fortCurrentIpExcluded' => $fortCurrentIpExcluded,
            'fortEffectiveLoginThreshold' => $effectiveLoginThreshold,
            'fortEffectiveLoginWindowMinutes' => $effectiveLoginWindowMinutes,
            'title' => Craft::t('fort', 'Events'),
            'docTitle' => Craft::t('fort', 'Events') . ' - ' . Craft::t('fort', 'Fort'),
            'crumbs' => [
                ['label' => Craft::t('fort', 'Fort'), 'url' => UrlHelper::cpUrl('fort/dashboard', $this->tabBaseParams())],
                ['label' => Craft::t('fort', 'Events'), 'url' => UrlHelper::cpUrl('fort/events', $this->tabBaseParams())],
            ],
            'selectedSubnavItem' => 'events',
        ], $this->fortActionUrls()), View::TEMPLATE_MODE_CP);
    }

    /**
     * HTML body for the runtime edit modal (GET) — returns raw HTML for Garnish.Modal.
     */
    public function actionRuntimeModal(): Response
    {
        $this->requireCpRequest();

        $plugin = Plugin::getInstance();
        $runtime = $plugin->runtimeSettings->getRow();
        /** @var \allomambo\fort\models\Settings $settings */
        $settings = $plugin->getSettings();
        $fortTunables = RuntimePresenter::tunables($runtime, $settings, $plugin->runtimeSettings);

        return $this->renderTemplate('fort/dashboard/_runtime-modal-body', [
            'fortRuntime' => $runtime,
            'fortTunables' => $fortTunables,
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * HTML body for the manual block IP modal (GET) — returns raw HTML for Garnish.Modal.
     */
    public function actionBlockIpModal(): Response
    {
        $this->requireCpRequest();

        return $this->renderTemplate('fort/dashboard/_block-ip-modal-body', [], View::TEMPLATE_MODE_CP);
    }

    public function actionClearEvents(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();

        $n = Plugin::getInstance()->securityEvents->deleteAllEvents();

        return $this->asJson([
            'success' => true,
            'deleted' => $n,
        ]);
    }

    public function actionSaveRuntime(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();

        $req = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $attrs = [
            'permanentBlockAfterAutomaticBlocks' => $req->getBodyParam('permanentBlockAfterAutomaticBlocks'),
            'failedLoginThreshold' => $req->getBodyParam('failedLoginThreshold'),
            'failedLoginWindowMinutes' => $req->getBodyParam('failedLoginWindowMinutes'),
            'maxRequestsPerIpPerMinute' => $req->getBodyParam('maxRequestsPerIpPerMinute'),
            'httpRateLimitAlertsBeforeBlock' => $req->getBodyParam('httpRateLimitAlertsBeforeBlock'),
            'httpRateLimitAlertWindowMinutes' => $req->getBodyParam('httpRateLimitAlertWindowMinutes'),
        ];

        $dur = $req->getBodyParam('defaultBlockDurationMinutes');
        $attrs['defaultBlockDurationMinutes'] = ($dur === '' || $dur === null)
            ? null
            : max(1, (int) $dur);

        foreach (['permanentBlockAfterAutomaticBlocks', 'failedLoginThreshold', 'failedLoginWindowMinutes', 'maxRequestsPerIpPerMinute', 'httpRateLimitAlertsBeforeBlock', 'httpRateLimitAlertWindowMinutes'] as $k) {
            if ($attrs[$k] === '' || $attrs[$k] === null) {
                $attrs[$k] = null;
            }
        }

        if (!$plugin->runtimeSettings->save($attrs)) {
            $this->response->setStatusCode(500);

            return $this->asJson(['success' => false, 'message' => Craft::t('fort', 'Could not save runtime settings.')]);
        }

        return $this->asJson(['success' => true]);
    }

    public function actionClearRuntime(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();

        if (!Plugin::getInstance()->runtimeSettings->clearAllOverrides()) {
            $this->response->setStatusCode(500);

            return $this->asJson(['success' => false, 'message' => Craft::t('fort', 'Could not clear runtime overrides.')]);
        }

        return $this->asJson(['success' => true]);
    }

    public function actionAddBlock(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();

        $ip = trim((string) Craft::$app->getRequest()->getBodyParam('ip'));
        $notes = Craft::$app->getRequest()->getBodyParam('notes');

        if (!IpHelper::isValidIp($ip)) {
            $this->response->setStatusCode(400);

            return $this->asJson(['success' => false, 'message' => Craft::t('fort', 'Enter a valid IPv4 or IPv6 address.')]);
        }

        $plugin = Plugin::getInstance();
        $normalizedIp = $plugin->ipBlocks->normalizeClientIp($ip);
        if (IpHelper::matchesExcluded($normalizedIp, $plugin->getSettings()->excludedIps)) {
            $this->response->setStatusCode(400);

            return $this->asJson(['success' => false, 'message' => Craft::t('fort', 'This IP is in the excluded list and cannot be blocked.')]);
        }

        $notesStr = is_string($notes) ? trim($notes) : null;
        $permanent = (bool) Craft::$app->getRequest()->getBodyParam('permanent');

        if (!$plugin->ipBlocks->applyManualBlock($ip, $notesStr !== '' ? $notesStr : null, $permanent)) {
            $this->response->setStatusCode(500);

            return $this->asJson(['success' => false, 'message' => Craft::t('fort', 'Could not save blocked IP.')]);
        }

        return $this->asJson(['success' => true]);
    }

    public function actionSaveNotes(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();

        $ip = trim((string) Craft::$app->getRequest()->getBodyParam('ip'));
        if (!IpHelper::isValidIp($ip)) {
            $this->response->setStatusCode(400);

            return $this->asJson(['success' => false, 'message' => Craft::t('fort', 'Enter a valid IPv4 or IPv6 address.')]);
        }

        $notes = Craft::$app->getRequest()->getBodyParam('notes');
        $notesStr = is_string($notes) ? trim($notes) : null;

        if (!Plugin::getInstance()->ipBlocks->updateNotes($ip, $notesStr !== '' ? $notesStr : null)) {
            $this->response->setStatusCode(500);

            return $this->asJson(['success' => false, 'message' => Craft::t('fort', 'Could not save notes.')]);
        }

        return $this->asJson(['success' => true]);
    }

    public function actionUnblock(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();

        $ip = trim((string) Craft::$app->getRequest()->getBodyParam('ip'));
        if (!IpHelper::isValidIp($ip)) {
            $this->response->setStatusCode(400);

            return $this->asJson(['success' => false, 'message' => Craft::t('fort', 'Enter a valid IPv4 or IPv6 address.')]);
        }

        if (!Plugin::getInstance()->ipBlocks->unblock($ip)) {
            $this->response->setStatusCode(500);

            return $this->asJson(['success' => false, 'message' => Craft::t('fort', 'Could not unblock that IP.')]);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Fort stores `dateCreated` in the DB as UTC; CP tables show raw strings. Map a display value in the app timezone.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function fortEventsWithCpDatetime(array $rows): array
    {
        foreach ($rows as &$row) {
            $parts = FortCpDatetime::parts($row['dateCreated'] ?? null);
            $row['dateTimeMain'] = $parts['main'];
            $row['typeLabel'] = EventDisplayHelper::eventTypeLabel((string) ($row['type'] ?? ''));
            $row['metaRows'] = EventDisplayHelper::metaTableRows($row);
            $row['triggeringUser'] = $this->resolveTriggeringUserFromStoredJson($row['meta'] ?? null);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function fortAlertsWithCpDatetime(array $rows): array
    {
        foreach ($rows as &$row) {
            $parts = FortCpDatetime::parts($row['dateCreated'] ?? null);
            $row['dateTimeMain'] = $parts['main'];
            $row['triggeringUser'] = $this->resolveTriggeringUserFromStoredJson($row['payload'] ?? null);
        }
        unset($row);

        return $rows;
    }

    private function resolveTriggeringUserFromStoredJson(?string $json): ?User
    {
        if ($json === null || $json === '') {
            return null;
        }
        try {
            $data = Json::decode($json, true);
            if (!is_array($data) || empty($data['triggeringUserId'])) {
                return null;
            }
            $uid = (int) $data['triggeringUserId'];

            return $uid > 0 ? User::find()->id($uid)->status(null)->one() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
