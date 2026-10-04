<?php

declare(strict_types=1);

namespace allomambo\fort\tests\unit;

use allomambo\fort\helpers\PiiRedactor;
use Craft;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PiiRedactorTest extends TestCase
{
    #[DataProvider('anonymizedIpProvider')]
    public function testAnonymizeIpMasksNetworkAddresses(string $input, string $expected): void
    {
        self::assertSame($expected, PiiRedactor::anonymizeIp($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function anonymizedIpProvider(): iterable
    {
        yield 'IPv4' => ['192.0.2.42', '192.0.2.0'];
        yield 'IPv4-mapped IPv6' => ['::ffff:192.0.2.42', '192.0.2.0'];
        yield 'IPv6' => ['2001:db8:abcd:1234::42', '2001:db8:abcd::'];
        yield 'unspecified IPv4' => ['0.0.0.0', '0.0.0.0'];
        yield 'empty' => [' ', ' '];
        yield 'invalid' => ['not-an-ip', 'not-an-ip'];
    }

    public function testRedactMetaRedactsLoginAndIpFieldsWithoutChangingOtherValues(): void
    {
        self::assertNull(Craft::$app);

        $meta = [
            'attemptedLogin' => 'Alice@Example.com',
            'ip' => '192.0.2.42',
            'blockedClientIp' => '2001:db8:abcd:1234::42',
            'userId' => 42,
            'reason' => 'failed-login',
        ];

        self::assertSame([
            'attemptedLogin' => PiiRedactor::REDACTION_MARKER,
            'ip' => '192.0.2.0',
            'blockedClientIp' => '2001:db8:abcd::',
            'userId' => 42,
            'reason' => 'failed-login',
        ], PiiRedactor::redactMeta($meta));
    }

    public function testHashLoginWithoutCraftAppReturnsMarkerWithoutLeakingInput(): void
    {
        self::assertNull(Craft::$app);

        $input = 'Alice@Example.com';
        $output = PiiRedactor::hashLogin($input);

        self::assertSame(PiiRedactor::REDACTION_MARKER, $output);
        self::assertStringNotContainsString($input, $output);
        self::assertStringNotContainsString(strtolower($input), strtolower($output));
    }

    public function testHashLoginUsesStableDomainSeparatedKeyedTokens(): void
    {
        $originalApp = Craft::$app;
        $input = 'Alice@Example.com';
        $normalized = 'alice@example.com';
        $key = 'fixed-security-key';

        try {
            Craft::$app = self::craftAppWithSecurityKey($key);

            $token = PiiRedactor::hashLogin($input);

            self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{12}$/', $token);
            self::assertSame($token, PiiRedactor::hashLogin('  ALICE@example.COM  '));
            self::assertNotSame($token, PiiRedactor::hashLogin('bob@example.com'));
            self::assertStringNotContainsString($input, $token);
            self::assertStringNotContainsString($normalized, $token);
            self::assertNotSame(
                'sha256:' . substr(hash_hmac('sha256', $normalized, $key), 0, 12),
                $token,
            );

            Craft::$app = self::craftAppWithSecurityKey('different-security-key');

            self::assertNotSame($token, PiiRedactor::hashLogin($input));
        } finally {
            Craft::$app = $originalApp;
        }
    }

    #[DataProvider('emptyLoginProvider')]
    public function testHashLoginReturnsEmptyForBlankInput(string $input): void
    {
        self::assertSame('', PiiRedactor::hashLogin($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyLoginProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'mixed whitespace' => [" \t\n "];
    }

    private static function craftAppWithSecurityKey(string $securityKey): object
    {
        return new class($securityKey) {
            public function __construct(private readonly string $securityKey)
            {
            }

            public function getConfig(): object
            {
                return new class($this->securityKey) {
                    public function __construct(private readonly string $securityKey)
                    {
                    }

                    public function getGeneral(): object
                    {
                        return (object) ['securityKey' => $this->securityKey];
                    }
                };
            }
        };
    }
}
