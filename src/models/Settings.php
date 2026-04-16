<?php

namespace allomambo\fort\models;

use allomambo\fort\helpers\IpHelper;
use Craft;
use craft\base\Model;

class Settings extends Model
{
    public bool $httpRateLimitEnabled = true;

    public int $maxRequestsPerIpPerMinute = 400;

    /**
     * How many debounced HTTP rate-limit alerts (one per calendar minute max) within the alert window trigger an automatic IP block.
     */
    public int $httpRateLimitAlertsBeforeBlock = 1;

    /**
     * Rolling window (minutes) for counting alerts toward {@see self::$httpRateLimitAlertsBeforeBlock}.
     */
    public int $httpRateLimitAlertWindowMinutes = 60;

    /** @var string[] CIDR or single IPs */
    public array $excludedIps = [];

    public bool $excludeCpFromHttpRateLimit = true;

    public bool $excludeCpResourcesFromHttpRateLimit = true;

    public bool $authLoggingEnabled = true;

    public int $failedLoginThresholdPerIp = 10;

    public int $failedLoginWindowMinutes = 15;

    /** Default duration for automatic and manual temporary IP blocks (minutes). */
    public int $defaultBlockDurationMinutes = 60;

    /**
     * Automatic block events on an IP: when total block count reaches this value, the block becomes permanent until manually cleared.
     * After unblocking a permanent block, the next threshold increments by the base value (e.g. 10 → 20 → 30 → 40) without resetting the counter.
     */
    public int $permanentBlockAfterAutomaticBlocks = 10;

    /**
     * When true, Fort periodically clears expired temporary IP blocks from the database
     * so the blocked-IP list stays accurate without waiting for each IP to revisit the site.
     */
    public bool $autoSweepExpiredIpBlocks = true;

    public bool $significantEventEmailEnabled = true;

    public bool $dailyDigestEmailEnabled = false;

    /** Hour 0–23 (system timezone) */
    public int $dailyDigestHour = 8;

    public bool $weeklyDigestEmailEnabled = false;

    /** 0 (Sunday) – 6 (Saturday) */
    public int $weeklyDigestDayOfWeek = 1;

    /**
     * When true, Fort may send scheduled digests after ordinary web requests (throttled).
     * Disable if you use server cron with the console commands to avoid overlapping sends.
     */
    public bool $digestSendOnActivity = true;

    /** @var int[] Craft user IDs */
    public array $maintainerUserIds = [];

    public string $webhookUrl = '';

    public bool $webhookOnSignificantEvent = false;

    public int $eventRetentionDays = 90;

    public function beforeValidate(): bool
    {
        // Lightswitches POST '' when off; normalize for boolean rules.
        foreach (
            [
                'httpRateLimitEnabled',
                'excludeCpFromHttpRateLimit',
                'excludeCpResourcesFromHttpRateLimit',
                'authLoggingEnabled',
                'significantEventEmailEnabled',
                'dailyDigestEmailEnabled',
                'weeklyDigestEmailEnabled',
                'digestSendOnActivity',
                'autoSweepExpiredIpBlocks',
                'webhookOnSignificantEvent',
            ] as $boolAttr
        ) {
            $v = $this->$boolAttr ?? null;
            if ($v === '' || $v === '0' || $v === 0) {
                $this->$boolAttr = false;
            } elseif ($v === '1' || $v === 1) {
                $this->$boolAttr = true;
            }
        }

        return parent::beforeValidate();
    }

    public function rules(): array
    {
        return [
            [['httpRateLimitEnabled', 'excludeCpFromHttpRateLimit', 'excludeCpResourcesFromHttpRateLimit', 'authLoggingEnabled', 'autoSweepExpiredIpBlocks', 'significantEventEmailEnabled', 'dailyDigestEmailEnabled', 'weeklyDigestEmailEnabled', 'digestSendOnActivity', 'webhookOnSignificantEvent'], 'boolean'],
            [['maxRequestsPerIpPerMinute', 'httpRateLimitAlertsBeforeBlock', 'httpRateLimitAlertWindowMinutes', 'failedLoginThresholdPerIp', 'failedLoginWindowMinutes', 'defaultBlockDurationMinutes', 'permanentBlockAfterAutomaticBlocks', 'dailyDigestHour', 'weeklyDigestDayOfWeek', 'eventRetentionDays'], 'integer'],
            [['maxRequestsPerIpPerMinute'], 'integer', 'min' => 1, 'max' => 1000000],
            [['httpRateLimitAlertsBeforeBlock'], 'integer', 'min' => 1, 'max' => 100000],
            [['httpRateLimitAlertWindowMinutes'], 'integer', 'min' => 1, 'max' => 10080],
            [['failedLoginThresholdPerIp'], 'integer', 'min' => 1, 'max' => 10000],
            [['failedLoginWindowMinutes'], 'integer', 'min' => 1, 'max' => 10080],
            [['defaultBlockDurationMinutes'], 'integer', 'min' => 1, 'max' => 525600],
            [['permanentBlockAfterAutomaticBlocks'], 'integer', 'min' => 1, 'max' => 100000],
            [['dailyDigestHour'], 'integer', 'min' => 0, 'max' => 23],
            [['weeklyDigestDayOfWeek'], 'integer', 'min' => 0, 'max' => 6],
            [['eventRetentionDays'], 'integer', 'min' => 1, 'max' => 3650],
            [['webhookUrl'], 'string', 'max' => 2048],
            [['webhookUrl'], 'url', 'pattern' => '/^https:\/\/.+/i', 'message' => Craft::t('fort', 'Webhook URL must be HTTPS.'), 'when' => fn() => $this->webhookUrl !== ''],
            [['webhookUrl'], 'validateWebhookUrlHostname', 'when' => fn() => $this->webhookUrl !== ''],
            [['excludedIps'], 'validateExcludedIps'],
            [['maintainerUserIds'], 'each', 'rule' => ['integer']],
        ];
    }

    /**
     * Reject webhook URLs that embed credentials, use a non-standard port, or whose host resolves
     * to a private / reserved / link-local / loopback / CGNAT / metadata IP (SSRF protection).
     */
    public function validateWebhookUrlHostname(string $attribute): void
    {
        $url = (string) $this->$attribute;
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            // The existing url rule already produced an error for this case.
            return;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            $this->addError($attribute, Craft::t('fort', 'Webhook URL must not include credentials.'));
            return;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            $this->addError($attribute, Craft::t('fort', 'Webhook URL must use the default HTTPS port (443).'));
            return;
        }

        $resolved = [];
        try {
            $ok = IpHelper::hostnameResolvesToPublicOnly((string) $parts['host'], $resolved);
        } catch (\Throwable) {
            $ok = false;
        }
        if (!$ok) {
            $this->addError($attribute, Craft::t('fort', 'Webhook URL host must resolve to a public IP address (no loopback, private, link-local, CGNAT, or metadata addresses).'));
        }
    }

    /**
     * Reject any excluded IP/CIDR entry that is not a valid IPv4, IPv6, IPv4 CIDR, or IPv6 CIDR.
     * Reports every bad line so admins can fix them in one round trip.
     */
    public function validateExcludedIps(): void
    {
        $list = is_array($this->excludedIps) ? $this->excludedIps : [];
        foreach ($list as $index => $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (!IpHelper::isValidCidrOrIp($line)) {
                $this->addError('excludedIps', Craft::t('fort', 'Invalid IP or CIDR on line {line}: "{value}". Use IPv4, IPv6, IPv4 CIDR, or IPv6 CIDR.', [
                    'line' => (int) $index + 1,
                    'value' => $line,
                ]));
            }
        }
    }

    /**
     * Ensure properties whose form fields use an alias name (e.g. excludedIpsText → excludedIps)
     * are always included in serialization, even when Craft filters by POST keys.
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        $data = parent::toArray($fields, $expand, $recursive);

        $alwaysInclude = ['excludedIps'];
        foreach ($alwaysInclude as $attr) {
            if (!array_key_exists($attr, $data)) {
                $data[$attr] = $this->$attr;
            }
        }

        return $data;
    }

    public function attributeLabels(): array
    {
        return [
            'httpRateLimitEnabled' => Craft::t('fort', 'Enable HTTP rate limiting'),
            'maxRequestsPerIpPerMinute' => Craft::t('fort', 'Max requests per IP per minute'),
            'httpRateLimitAlertsBeforeBlock' => Craft::t('fort', 'HTTP rate-limit alerts before automatic IP block'),
            'httpRateLimitAlertWindowMinutes' => Craft::t('fort', 'HTTP rate-limit alert window (minutes)'),
            'excludedIps' => Craft::t('fort', 'Excluded IPs / CIDRs'),
            'excludeCpFromHttpRateLimit' => Craft::t('fort', 'Exclude Control Panel requests'),
            'excludeCpResourcesFromHttpRateLimit' => Craft::t('fort', 'Exclude CP resource URLs'),
            'authLoggingEnabled' => Craft::t('fort', 'Log authentication attempts'),
            'failedLoginThresholdPerIp' => Craft::t('fort', 'Failed logins per IP before alert'),
            'failedLoginWindowMinutes' => Craft::t('fort', 'Failed login window (minutes)'),
            'defaultBlockDurationMinutes' => Craft::t('fort', 'Default IP block duration (minutes)'),
            'permanentBlockAfterAutomaticBlocks' => Craft::t('fort', 'Automatic blocks before permanent'),
            'autoSweepExpiredIpBlocks' => Craft::t('fort', 'Auto-sweep expired IP blocks'),
            'significantEventEmailEnabled' => Craft::t('fort', 'Email on significant events'),
            'dailyDigestEmailEnabled' => Craft::t('fort', 'Daily digest email'),
            'dailyDigestHour' => Craft::t('fort', 'Daily digest hour'),
            'weeklyDigestEmailEnabled' => Craft::t('fort', 'Weekly digest email'),
            'weeklyDigestDayOfWeek' => Craft::t('fort', 'Weekly digest day'),
            'digestSendOnActivity' => Craft::t('fort', 'Send scheduled digests on web activity'),
            'maintainerUserIds' => Craft::t('fort', 'Maintainer recipients'),
            'webhookUrl' => Craft::t('fort', 'Webhook URL (HTTPS)'),
            'webhookOnSignificantEvent' => Craft::t('fort', 'POST webhook on significant events'),
            'eventRetentionDays' => Craft::t('fort', 'Retain events (days)'),
        ];
    }
}
