<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\UrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlValidatorTest extends TestCase
{
    #[DataProvider('validUrls')]
    public function testAcceptsValidHttpUrls(string $url): void
    {
        self::assertTrue(UrlValidator::isValidHttpUrl($url));
    }

    /** @return array<string, array{0: string}> */
    public static function validUrls(): array
    {
        return [
            'https' => ['https://example.com/path?x=1'],
            'http' => ['http://example.com'],
            'with-port' => ['https://example.com:8080/x'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function testRejectsInvalidUrls(string $url): void
    {
        self::assertFalse(UrlValidator::isValidHttpUrl($url));
    }

    /** @return array<string, array{0: string}> */
    public static function invalidUrls(): array
    {
        return [
            'javascript-scheme' => ['javascript:alert(1)'],
            'data-scheme' => ['data:text/html,<script>alert(1)</script>'],
            'empty' => [''],
            'no-scheme' => ['example.com'],
            'ftp-scheme' => ['ftp://example.com/file'],
            'too-long' => ['https://example.com/' . str_repeat('a', 2050)],
        ];
    }
}
