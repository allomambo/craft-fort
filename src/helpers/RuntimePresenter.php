<?php

namespace allomambo\fort\helpers;

use allomambo\fort\models\Settings;
use allomambo\fort\records\FortRuntimeRecord;
use allomambo\fort\services\RuntimeSettingsService;
use Craft;

/**
 * Stateless presenter: builds view-ready tunable and feature-gate arrays for the CP overview and modal.
 */
final class RuntimePresenter
{
    /**
     * @return array<string, array{
     *   key: string,
     *   label: string,
     *   effective: int,
     *   runtimeValue: int|null,
     *   baseFromSettings: int,
     *   isRuntimeOverridden: bool,
     *   settingsAttr: string,
     *   isConfigFileDefined: bool,
     *   isConfigEnvSpecific: bool,
     *   source: 'runtime'|'config'|'settings',
     * }>
     */
    public static function tunables(FortRuntimeRecord $runtime, Settings $settings, RuntimeSettingsService $runtimeService): array
    {
        $map = [
            'defaultBlockDurationMinutes' => [
                'label' => Craft::t('fort', 'Default block duration'),
                'settingsAttr' => 'defaultBlockDurationMinutes',
                'base' => (int) $settings->defaultBlockDurationMinutes,
                'effective' => $runtimeService->getDefaultBlockDurationMinutes(),
            ],
            'permanentBlockAfterAutomaticBlocks' => [
                'label' => Craft::t('fort', 'Automatic blocks before permanent'),
                'settingsAttr' => 'permanentBlockAfterAutomaticBlocks',
                'base' => (int) $settings->permanentBlockAfterAutomaticBlocks,
                'effective' => $runtimeService->getPermanentBlockAfterAutomaticBlocks($settings),
            ],
            'failedLoginThreshold' => [
                'label' => Craft::t('fort', 'Failed login threshold override'),
                'settingsAttr' => 'failedLoginThresholdPerIp',
                'base' => (int) $settings->failedLoginThresholdPerIp,
                'effective' => $runtimeService->getFailedLoginThreshold($settings),
            ],
            'failedLoginWindowMinutes' => [
                'label' => Craft::t('fort', 'Failed login window override'),
                'settingsAttr' => 'failedLoginWindowMinutes',
                'base' => (int) $settings->failedLoginWindowMinutes,
                'effective' => $runtimeService->getFailedLoginWindowMinutes($settings),
            ],
            'maxRequestsPerIpPerMinute' => [
                'label' => Craft::t('fort', 'Max HTTP requests per IP override'),
                'settingsAttr' => 'maxRequestsPerIpPerMinute',
                'base' => (int) $settings->maxRequestsPerIpPerMinute,
                'effective' => $runtimeService->getMaxRequestsPerIpPerMinute($settings),
            ],
            'httpRateLimitAlertsBeforeBlock' => [
                'label' => Craft::t('fort', 'HTTP rate-limit alerts before block override'),
                'settingsAttr' => 'httpRateLimitAlertsBeforeBlock',
                'base' => (int) $settings->httpRateLimitAlertsBeforeBlock,
                'effective' => $runtimeService->getHttpRateLimitAlertsBeforeBlock($settings),
            ],
            'httpRateLimitAlertWindowMinutes' => [
                'label' => Craft::t('fort', 'HTTP rate-limit alert window override'),
                'settingsAttr' => 'httpRateLimitAlertWindowMinutes',
                'base' => (int) $settings->httpRateLimitAlertWindowMinutes,
                'effective' => $runtimeService->getHttpRateLimitAlertWindowMinutes($settings),
            ],
        ];

        $result = [];
        foreach ($map as $key => $info) {
            $rawVal = $runtime->$key;
            $runtimeValue = ($rawVal !== null && (int) $rawVal > 0) ? (int) $rawVal : null;
            $isRuntimeOverridden = $runtimeValue !== null;
            $isConfigFileDefined = ConfigOverrideHelper::isOverridden($info['settingsAttr']);
            $isConfigEnvSpecific = ConfigOverrideHelper::isEnvSpecific($info['settingsAttr']);

            if ($isRuntimeOverridden) {
                $source = 'runtime';
            } elseif ($isConfigFileDefined) {
                $source = 'config';
            } else {
                $source = 'settings';
            }

            $result[$key] = [
                'key' => $key,
                'label' => $info['label'],
                'effective' => $info['effective'],
                'runtimeValue' => $runtimeValue,
                'baseFromSettings' => $info['base'],
                'settingsAttr' => $info['settingsAttr'],
                'isRuntimeOverridden' => $isRuntimeOverridden,
                'isConfigFileDefined' => $isConfigFileDefined,
                'isConfigEnvSpecific' => $isConfigEnvSpecific,
                'source' => $source,
            ];
        }

        return $result;
    }

    /**
     * @return array{httpRateLimitEnabled: bool, authLoggingEnabled: bool}
     */
    public static function featureGates(Settings $settings): array
    {
        return [
            'httpRateLimitEnabled' => (bool) $settings->httpRateLimitEnabled,
            'authLoggingEnabled' => (bool) $settings->authLoggingEnabled,
        ];
    }
}
