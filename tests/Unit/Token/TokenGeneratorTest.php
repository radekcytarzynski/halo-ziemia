<?php

declare(strict_types=1);

namespace App\Tests\Unit\Token;

use App\Token\TokenGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenGeneratorTest extends TestCase
{
    public function testGeneratedTokenFormat(): void
    {
        $token = (new TokenGenerator())->generate();

        self::assertMatchesRegularExpression('/^hz_[0-9A-Za-z]{48}$/D', $token->plainText);
        self::assertSame(substr($token->plainText, 0, 10), $token->displayPrefix);
        self::assertSame(hash('sha256', $token->plainText), $token->hash);
        self::assertTrue(TokenGenerator::looksValid($token->plainText));
    }

    public function testTokensAreUnique(): void
    {
        $generator = new TokenGenerator();
        $tokens = [];
        for ($i = 0; $i < 200; ++$i) {
            $tokens[] = $generator->generate()->plainText;
        }

        self::assertCount(200, array_unique($tokens));
    }

    #[DataProvider('looksValidProvider')]
    public function testLooksValid(string $candidate, bool $expected): void
    {
        self::assertSame($expected, TokenGenerator::looksValid($candidate));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function looksValidProvider(): iterable
    {
        yield 'poprawny' => ['hz_'.str_repeat('a', 48), true];
        yield 'minimalna długość' => ['hz_'.str_repeat('Z', 40), true];
        yield 'za krótki' => ['hz_'.str_repeat('a', 39), false];
        yield 'za długi' => ['hz_'.str_repeat('a', 129), false];
        yield 'zły prefiks' => ['xx_'.str_repeat('a', 48), false];
        yield 'niedozwolone znaki' => ['hz_'.str_repeat('a', 47).'-', false];
        yield 'nowa linia na końcu' => ['hz_'.str_repeat('a', 48)."\n", false];
        yield 'pusty' => ['', false];
    }
}
