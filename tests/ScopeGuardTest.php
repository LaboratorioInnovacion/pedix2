<?php declare(strict_types=1);
namespace Tests;

final class ScopeGuardTest extends TestCase
{
    public function testDomainPrimitivesDoNotEncodeBusinessWorkflowRules(): void
    {
        $root = dirname(__DIR__) . '/api/app/Domain'; $forbidden = '/\b(auth|catalog|pricing|order|payment|inventory|delivery|checkout|permission)\b/i';
        foreach (glob($root . '/*.php') ?: [] as $file) {
            $this->assertTrue(preg_match($forbidden, file_get_contents($file) ?: '') !== 1, basename($file) . ' contains deferred business workflow language.');
        }
    }
}
