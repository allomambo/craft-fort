<?php

namespace allomambo\fort\helpers;

use Craft;

/**
 * Which settings keys are defined in config/fort.php for the current environment.
 *
 * Craft merges `*` and env-specific blocks; this reflects keys that override stored CP settings at runtime.
 */
final class ConfigOverrideHelper
{
    /** @var array{merged: string[], envSpecific: string[]}|null */
    private static ?array $resolved = null;

    /**
     * @return list<string>
     */
    public static function fileDefinedKeys(): array
    {
        return self::resolve()['merged'];
    }

    public static function isOverridden(string $attribute): bool
    {
        return in_array($attribute, self::fileDefinedKeys(), true);
    }

    /**
     * Whether the key is defined in the current env-specific block (not just `*`).
     */
    public static function isEnvSpecific(string $attribute): bool
    {
        return in_array($attribute, self::resolve()['envSpecific'], true);
    }

    /**
     * @return array{merged: string[], envSpecific: string[]}
     */
    private static function resolve(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $config = Craft::$app->getConfig()->getConfigFromFile('fort');

        if (!is_array($config) || $config === []) {
            return self::$resolved = ['merged' => [], 'envSpecific' => []];
        }

        $merged = array_keys($config);

        $raw = self::rawConfigArray();
        $env = Craft::$app->getConfig()->env ?: 'production';
        $envKeys = isset($raw[$env]) && is_array($raw[$env]) ? array_keys($raw[$env]) : [];

        return self::$resolved = [
            'merged' => $merged,
            'envSpecific' => $envKeys,
        ];
    }

    /**
     * Read the raw config file without Craft's env merge so we can inspect
     * which keys live under `*` vs the current environment block.
     *
     * @return array<string, mixed>
     */
    private static function rawConfigArray(): array
    {
        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'fort.php';

        if (!file_exists($path)) {
            return [];
        }

        $raw = require $path;

        if (!is_array($raw)) {
            return [];
        }

        $hasEnvKeys = isset($raw['*']) && is_array($raw['*']);

        return $hasEnvKeys ? $raw : [];
    }
}
