<?php

namespace allomambo\fort\services;

use allomambo\fort\events\SecurityHeadersEvent;
use allomambo\fort\models\Settings;
use allomambo\fort\Plugin;
use Craft;
use craft\base\Component;
use yii\web\Response;

/**
 * Resolves Fort's configured static security response headers and applies them to the response.
 */
class SecurityHeadersService extends Component
{
    /**
     * @event SecurityHeadersEvent The event that is triggered before Fort's resolved security
     * headers are written onto the response. Listeners may add, change, or remove entries in
     * {@see SecurityHeadersEvent::$headers}.
     */
    public const EVENT_BEFORE_EMIT_SECURITY_HEADERS = 'beforeEmitSecurityHeaders';

    /**
     * Resolve the header map from settings. Returns `[]` when the master switch is off, or when
     * a given header's configured value is empty (that's what makes HSTS and CSP opt-in).
     *
     * @return array<string, string>
     */
    public function buildHeaders(bool $isCpRequest): array
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->emitSecurityHeaders) {
            return [];
        }

        $headers = [];
        foreach ($this->candidateHeaders($isCpRequest, $settings) as $name => $rawValue) {
            $value = self::sanitizeHeaderValue((string) $rawValue);
            if ($value === '') {
                continue;
            }
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * Apply Fort's security headers to the given response, letting listeners adjust the map first.
     */
    public function applyTo(Response $response): void
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->emitSecurityHeaders) {
            return;
        }

        $isCpRequest = Craft::$app->getRequest()->getIsCpRequest();

        $event = new SecurityHeadersEvent(['headers' => $this->buildHeaders($isCpRequest)]);
        $this->trigger(self::EVENT_BEFORE_EMIT_SECURITY_HEADERS, $event);

        $responseHeaders = $response->getHeaders();
        foreach ($event->headers as $name => $value) {
            $name = trim((string) $name);
            $value = self::sanitizeHeaderValue((string) $value);
            if ($name === '' || $value === '') {
                continue;
            }

            // setDefault(): headers already set by the project (templates, controllers) or the
            // server config must win over Fort's defaults, never be clobbered by them.
            $responseHeaders->setDefault($name, $value);
        }
    }

    /**
     * @return array<string, string>
     */
    private function candidateHeaders(bool $isCpRequest, Settings $settings): array
    {
        $headers = [
            'X-Content-Type-Options' => $settings->xContentTypeOptions,
            'Referrer-Policy' => $settings->referrerPolicy,
        ];

        // Site-only: a misconfigured site CSP/HSTS must never be able to break the Control Panel.
        if (!$isCpRequest) {
            $headers['Permissions-Policy'] = $settings->permissionsPolicy;
            // Never inferred from Craft::$app->getRequest()->getIsSecureConnection(): emitting HSTS
            // based on the current request's scheme is a pinning footgun (e.g. a local HTTPS dev
            // proxy would pin a header into browsers for the production host too). Opt-in only.
            $headers['Strict-Transport-Security'] = $settings->strictTransportSecurity;
            $headers['Content-Security-Policy'] = $settings->contentSecurityPolicy;
            $headers['Content-Security-Policy-Report-Only'] = $settings->contentSecurityPolicyReportOnly;
        }

        return $headers;
    }

    /**
     * Strip control characters (including CR/LF) defensively: config/fort.php values bypass
     * {@see Settings::validateHeaderValue()}, so a bad config value must never reach the response.
     */
    private static function sanitizeHeaderValue(string $value): string
    {
        $stripped = preg_replace('/[\x00-\x1F\x7F]/', '', $value);

        return trim($stripped ?? '');
    }
}
