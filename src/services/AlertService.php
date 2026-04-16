<?php

namespace allomambo\fort\services;

use allomambo\fort\helpers\AlertDisplayHelper;
use allomambo\fort\records\AlertRecord;
use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTimeZone;
use craft\base\Component;

class AlertService extends Component
{
    /**
     * Store an alert row before email/webhook delivery.
     *
     * @param array<string, mixed> $payload
     */
    public function record(string $alertType, string $clientIp, ?string $requestPath, array $payload): bool
    {
        $record = new AlertRecord();
        $record->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $record->alertType = StringHelper::truncate($alertType, 64);
        $record->clientIp = StringHelper::truncate($clientIp, 45);
        $record->requestPath = $requestPath !== null ? StringHelper::truncate($requestPath, 1024) : null;
        $record->payload = Json::encode($payload);
        $record->uid = StringHelper::UUID();
        $record->dateCreated = new \DateTime();

        if (!$record->save(false)) {
            Craft::warning('Fort: failed to save alert record', __METHOD__);

            return false;
        }

        return true;
    }

    public function pruneOlderThanDays(int $days): int
    {
        $tz = new DateTimeZone(Craft::$app->getTimeZone());
        $cutoff = DateTimeHelper::now($tz);
        $cutoff->modify('-' . $days . ' days');
        $cutoffDb = Db::prepareDateForDb($cutoff);
        if ($cutoffDb === null) {
            return 0;
        }

        return AlertRecord::deleteAll(['<', 'dateCreated', $cutoffDb]);
    }

    /**
     * Recent alert rows for the CP dashboard (payload decoded for display).
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentForCp(int $limit = 200): array
    {
        $rows = AlertRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->asArray()
            ->all();

        foreach ($rows as &$row) {
            $type = (string) ($row['alertType'] ?? '');
            $row['alertTypeLabel'] = AlertDisplayHelper::alertTypeLabel($type);

            $raw = $row['payload'] ?? null;
            $row['metaRows'] = [];
            if ($raw !== null && $raw !== '') {
                $decoded = json_decode((string) $raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $rp = $row['requestPath'] ?? null;
                    if ($rp !== null && $rp !== '' && (!isset($decoded['requestPath']) || $decoded['requestPath'] === '' || $decoded['requestPath'] === null)) {
                        $decoded['requestPath'] = $rp;
                    }
                    $row['metaRows'] = AlertDisplayHelper::metaTableRows($decoded);
                }
            }
        }
        unset($row);

        return $rows;
    }
}
