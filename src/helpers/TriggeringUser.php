<?php

namespace allomambo\fort\helpers;

use Craft;

final class TriggeringUser
{
    /**
     * Adds the current site user as `triggeringUserId` unless the key is already set.
     *
     * Only site requests attribute the user: CP actions (e.g. manual IP block) must not credit the
     * current admin session as the triggering user, and console requests have no user.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function stamp(array $data): array
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
            return $data;
        }

        $identity = Craft::$app->getUser()->getIdentity();
        if ($identity !== null && !isset($data['triggeringUserId'])) {
            $data['triggeringUserId'] = $identity->id;
        }

        return $data;
    }
}
