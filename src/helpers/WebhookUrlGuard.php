<?php

namespace allomambo\fort\helpers;

use Craft;
use GuzzleHttp\Exception\RequestException;

/**
 * Single source of truth for webhook URL safety, shared by the settings validator, the Control Panel
 * warning banner, and every send in {@see \allomambo\fort\services\NotificationService::postWebhook()}
 * so the three can never drift apart.
 */
final class WebhookUrlGuard
{
    /**
     * Reject webhook URLs that are not plain `https://`, that embed credentials, that use a non-default
     * port, or whose host resolves to a private / reserved / link-local / loopback / CGNAT / metadata IP
     * (SSRF protection).
     *
     * Fails closed: a parse failure, a missing host, a DNS error, and an empty resolution all reject.
     * DNS answers can change after a URL is saved, so callers must re-run this on every send.
     *
     * The scheme test is case-sensitive on purpose: Guzzle is handed the URL verbatim, and a lowercase
     * `https://` prefix is the only form every call site agrees on.
     *
     * @return array{message: string, logReason: string}|null Null when the URL is safe to post to.
     *                                                        `message` is translated for admins;
     *                                                        `logReason` is untranslated for logs.
     */
    public static function rejection(string $url): ?array
    {
        if (!str_starts_with($url, 'https://')) {
            return [
                'message' => Craft::t('fort', 'Webhook URL must be HTTPS.'),
                'logReason' => 'URL does not start with https://.',
            ];
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return [
                'message' => Craft::t('fort', 'Webhook URL could not be parsed.'),
                'logReason' => 'could not parse URL host.',
            ];
        }

        $host = (string) $parts['host'];

        if (isset($parts['user']) || isset($parts['pass'])) {
            return [
                'message' => Craft::t('fort', 'Webhook URL must not include credentials.'),
                'logReason' => 'URL for host ' . $host . ' contains credentials.',
            ];
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return [
                'message' => Craft::t('fort', 'Webhook URL must use the default HTTPS port (443).'),
                'logReason' => 'non-default HTTPS port ' . (int) $parts['port'] . ' on host ' . $host . '.',
            ];
        }

        $resolved = [];
        try {
            $public = IpHelper::hostnameResolvesToPublicOnly($host, $resolved);
        } catch (\Throwable $e) {
            return [
                'message' => Craft::t('fort', 'Webhook URL host could not be resolved.'),
                'logReason' => 'resolution of host ' . $host . ' failed (' . $e::class . ').',
            ];
        }

        if (!$public) {
            return [
                'message' => Craft::t('fort', 'Webhook URL host must resolve to a public IP address (no loopback, private, link-local, CGNAT, or metadata addresses).'),
                'logReason' => 'host ' . $host . ' resolved to private/reserved address(es): ' . implode(',', $resolved ?? []),
            ];
        }

        return null;
    }

    /**
     * Describe a failed webhook send for the log without quoting the URL.
     *
     * Guzzle and cURL put the full request URI in their exception messages, and a webhook URL commonly
     * carries its own secret in the path or query (Slack, Discord, and Teams all work that way), so a
     * send failure may only ever be reported as the host, the exception class, and — when the target
     * answered — the HTTP status. Never the path, query, userinfo, or the exception message.
     */
    public static function sendFailureLogReason(string $host, \Throwable $e): string
    {
        $details = [$e::class];

        if ($e instanceof RequestException && $e->getResponse() !== null) {
            $details[] = 'HTTP ' . $e->getResponse()->getStatusCode();
        }

        return 'host ' . $host . ' (' . implode(', ', $details) . ').';
    }
}
