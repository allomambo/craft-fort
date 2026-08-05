<?php

namespace allomambo\fort\events;

use yii\base\Event;

/**
 * Fired before Fort writes its resolved security headers onto the response.
 */
class SecurityHeadersEvent extends Event
{
    /** @var array<string, string> Header name => value; mutable — listeners may add, change, or remove entries. */
    public array $headers = [];
}
