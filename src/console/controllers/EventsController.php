<?php

namespace allomambo\fort\console\controllers;

use allomambo\fort\Plugin;
use craft\console\Controller;

class EventsController extends Controller
{
    /**
     * Remove Fort security events older than the configured retention period.
     */
    public function actionPrune(): int
    {
        $plugin = Plugin::getInstance();
        $days = $plugin->getSettings()->eventRetentionDays;
        $n = $plugin->securityEvents->pruneOlderThanDays($days);
        $na = $plugin->alerts->pruneOlderThanDays($days);
        $this->stdout("Pruned {$n} Fort security event(s) and {$na} alert row(s) older than {$days} day(s).\n");

        return self::EXIT_CODE_NORMAL;
    }

    /**
     * Send the daily digest email (intended for cron).
     */
    public function actionSendDailyDigest(): int
    {
        Plugin::getInstance()->notifications->sendDailyDigestForced();
        $this->stdout("Daily digest sent (if enabled and maintainers configured).\n");

        return self::EXIT_CODE_NORMAL;
    }

    /**
     * Send the weekly digest email (intended for cron).
     */
    public function actionSendWeeklyDigest(): int
    {
        Plugin::getInstance()->notifications->sendWeeklyDigestForced();
        $this->stdout("Weekly digest sent (if enabled and maintainers configured).\n");

        return self::EXIT_CODE_NORMAL;
    }
}
