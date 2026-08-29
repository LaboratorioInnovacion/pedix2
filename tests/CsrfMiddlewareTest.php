<?php declare(strict_types=1);
namespace Tests;

use VO\Auth\CsrfService;

final class CsrfMiddlewareTest extends TestCase
{
    public function testCsrfValidAndInvalidControlsStateChange(): void
    {
        $store = [];
        $csrf = new CsrfService($store);
        $token = $csrf->issue();
        $state = 0;
        if ($csrf->validate($token)) $state++;
        if ($csrf->validate('invalid')) $state++;
        $this->assertSame(1, $state);
    }
}
