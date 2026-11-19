<?php

declare(strict_types=1);

namespace App\Token;

/**
 * Generuje tokeny agentów: "hz_" + 48 znaków base62 z CSPRNG (~285 bitów entropii).
 */
final class TokenGenerator
{
    public const string PREFIX = 'hz_';
    public const int RANDOM_LENGTH = 48;
    /** Długość widocznego w UI prefiksu (np. "hz_Ab3dE9x"). */
    public const int DISPLAY_PREFIX_LENGTH = 10;

    private const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function generate(): GeneratedToken
    {
        $random = '';
        $max = \strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::RANDOM_LENGTH; ++$i) {
            $random .= self::ALPHABET[random_int(0, $max)];
        }

        $plain = self::PREFIX.$random;

        return new GeneratedToken(
            plainText: $plain,
            displayPrefix: substr($plain, 0, self::DISPLAY_PREFIX_LENGTH),
            hash: self::hash($plain),
        );
    }

    public static function hash(#[\SensitiveParameter] string $plainText): string
    {
        return hash('sha256', $plainText);
    }

    /**
     * Szybkie odrzucenie oczywiście błędnych tokenów, zanim zapytamy bazę.
     */
    public static function looksValid(#[\SensitiveParameter] string $plainText): bool
    {
        return 1 === preg_match('/^hz_[0-9A-Za-z]{40,128}$/D', $plainText);
    }
}
