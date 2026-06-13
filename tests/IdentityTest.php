<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Identity;

final class IdentityTest extends TestCase
{
    protected function tearDown(): void
    {
        Identity::reset();
        unset($_COOKIE[Identity::COOKIE]);
    }

    public function testMintIsValidUuid(): void
    {
        $id = Identity::mint();
        $this->assertTrue(Identity::isValid($id));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
        $this->assertNotSame(Identity::mint(), Identity::mint());
    }

    public function testRejectsTampered(): void
    {
        $this->assertFalse(Identity::isValid('bad value!'));
        $this->assertFalse(Identity::isValid(null));
    }

    public function testEnsureReusesValidCookie(): void
    {
        $_COOKIE[Identity::COOKIE] = 'stable-id-1';
        $this->assertSame('stable-id-1', Identity::ensure());
        $this->assertSame('stable-id-1', Identity::current());
    }

    public function testEnsureMintsWhenAbsentOrTampered(): void
    {
        unset($_COOKIE[Identity::COOKIE]);
        $id = Identity::ensure();
        $this->assertTrue(Identity::isValid($id));
        $this->assertSame($id, Identity::current());
        // Same request sees the minted id.
        $this->assertSame($id, $_COOKIE[Identity::COOKIE]);

        Identity::reset();
        $_COOKIE[Identity::COOKIE] = 'bad value!';
        $id2 = Identity::ensure();
        $this->assertNotSame('bad value!', $id2);
        $this->assertTrue(Identity::isValid($id2));
    }
}
