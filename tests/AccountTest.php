<?php

declare(strict_types=1);

namespace GaConnector\Tracking\Tests;

use GaConnector\Tracking\Account;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    public function testAllowsExactMatchCaseInsensitive(): void
    {
        $account = new Account('acc', 'Acme', 'o@e.com', ['Example.COM']);

        self::assertTrue($account->allows('example.com'));
        self::assertTrue($account->allows(' EXAMPLE.com '));
    }

    public function testAllowsRejectsUnrelatedHost(): void
    {
        $account = new Account('acc', 'Acme', 'o@e.com', ['example.com']);

        self::assertFalse($account->allows('other.com'));
        self::assertFalse($account->allows(''));
        self::assertFalse($account->allows('   '));
    }

    public function testAllowsSubdomainOfListedApex(): void
    {
        $account = new Account('acc', 'Acme', 'o@e.com', ['gaconnector.com']);

        self::assertTrue($account->allows('www-staging.gaconnector.com'));
        self::assertTrue($account->allows('shop.gaconnector.com'));
    }

    public function testAllowsRejectsLookalikeHost(): void
    {
        $account = new Account('acc', 'Acme', 'o@e.com', ['gaconnector.com']);

        self::assertFalse($account->allows('notgaconnector.com'));
        self::assertFalse($account->allows('evil-gaconnector.com'));
        self::assertFalse($account->allows('gaconnector.com.evil.example'));
    }

    public function testAllowsIgnoresEmptyAllowedEntries(): void
    {
        $account = new Account('acc', 'Acme', 'o@e.com', ['', 'example.com']);

        self::assertTrue($account->allows('example.com'));
        self::assertFalse($account->allows(''));
    }
}
