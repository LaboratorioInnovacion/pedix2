<?php declare(strict_types=1);
namespace Tests;

use VO\Auth\AuthorizationMiddleware;
use VO\Auth\BranchScope;
use VO\Auth\PermissionGuard;
use VO\Http\JsonResponse;
use VO\Http\Request;

require_once __DIR__ . '/AuthSessionTest.php';

final class PermissionGuardTest extends TestCase
{
    public function testPermissionAllowedAndDeniedByRolePermissionChain(): void
    {
        $scratch = AuthScratchDatabase::create(); if ($scratch === null) return;
        try {
            $ids = $this->seedRbac($scratch);
            $owner = new PermissionGuard($scratch->pdo, $ids['owner']);
            $user = new PermissionGuard($scratch->pdo, $ids['limited']);
            $this->assertSame(true, $owner->requirePermission('payments.verify_transfer'));
            $this->assertSame(false, $user->requirePermission('payments.verify_transfer'));
            $this->assertSame(true, $user->requirePermission('orders.view'));
        } finally { $scratch->drop(); }
    }

    public function testGlobalActionsRequirePermissionOnlyWithoutBranch(): void
    {
        $scratch = AuthScratchDatabase::create(); if ($scratch === null) return;
        try {
            $ids = $this->seedRbac($scratch);
            $middleware = new AuthorizationMiddleware($scratch->pdo);
            foreach (['users.manage', 'settings.manage', 'reports.view'] as $permission) {
                $response = $middleware->authorize($this->request('req-global'), $ids['global'], $permission, null, static fn () => JsonResponse::ok(['allowed' => true]));
                $this->assertSame(200, $response->status(), $permission . ' should not require a branch.');
            }
        } finally { $scratch->drop(); }
    }

    public function testOperationalActionsRequireExplicitBranchAssignment(): void
    {
        $scratch = AuthScratchDatabase::create(); if ($scratch === null) return;
        try {
            $ids = $this->seedRbac($scratch);
            $scope = new BranchScope($scratch->pdo);
            $this->assertSame(false, $scope->requireBranch($ids['limited'], $ids['branch']));
            $scratch->pdo->prepare('INSERT INTO user_branches (user_id,branch_id) VALUES (?,?)')->execute([$ids['limited'], $ids['branch']]);
            $this->assertSame(true, $scope->requireBranch($ids['limited'], $ids['branch']));

            $middleware = new AuthorizationMiddleware($scratch->pdo);
            $allowed = $middleware->authorize($this->request('req-branch-ok'), $ids['limited'], 'orders.view', $ids['branch'], static fn () => JsonResponse::ok(['allowed' => true]));
            $this->assertSame(200, $allowed->status());
        } finally { $scratch->drop(); }
    }

    public function testDirectDeniedRequestReturnsJson403AndAuditWithRequestId(): void
    {
        $scratch = AuthScratchDatabase::create(); if ($scratch === null) return;
        try {
            $ids = $this->seedRbac($scratch);
            $middleware = new AuthorizationMiddleware($scratch->pdo);
            $response = $middleware->authorize($this->request('req-denied'), $ids['limited'], 'deliveries.assign', $ids['branch'], static fn () => JsonResponse::ok(['allowed' => true]));
            $body = json_decode($response->body(), true);
            $this->assertSame(403, $response->status());
            $this->assertSame(false, $body['ok']);
            $this->assertSame('forbidden', $body['error']['code']);
            $this->assertSame('req-denied', $body['request_id']);
            $count = (int)$scratch->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='authz.denied' AND request_id='req-denied'")->fetchColumn();
            $this->assertSame(1, $count);
        } finally { $scratch->drop(); }
    }

    private function request(string $requestId): Request
    {
        return new Request('POST', '/protected', ['request_id' => $requestId]);
    }

    private function seedRbac(AuthScratchDatabase $scratch): array
    {
        $scratch->migrateAndSeedUser();
        $pdo = $scratch->pdo;
        $pdo->exec("INSERT INTO branches (id,business_id,name) VALUES (1,1,'Main')");
        $pdo->exec("INSERT INTO users (id,business_id,name,email,password_hash,is_active) VALUES (2,1,'Limited','limited@example.test','x',1),(3,1,'Global','global@example.test','x',1)");
        foreach (['owner' => 1, 'limited' => 2, 'global' => 3] as $role => $id) $pdo->exec("INSERT INTO roles (id,name,label,is_system) VALUES ($id,'$role','$role',1)");
        // Migration 003 seeds products.manage at migration time; clear it so the
        // explicit-id fixture below starts from an empty permissions table.
        $pdo->exec("DELETE FROM permissions WHERE permission_key='products.manage'");
        $permissions = ['orders.view','orders.accept','orders.reject','orders.modify','orders.prepare','orders.mark_ready','orders.cancel','products.edit_price','products.change_availability','products.manage_stock','deliveries.assign','deliveries.reassign','payments.verify_transfer','settings.manage','users.manage','reports.view'];
        foreach ($permissions as $i => $key) $pdo->prepare('INSERT INTO permissions (id,permission_key,label) VALUES (?,?,?)')->execute([$i + 1, $key, $key]);
        foreach (range(1, 16) as $permissionId) $pdo->prepare('INSERT INTO role_permissions (role_id,permission_id) VALUES (1,?)')->execute([$permissionId]);
        foreach (['orders.view', 'settings.manage', 'users.manage', 'reports.view'] as $key) $pdo->prepare('INSERT INTO role_permissions (role_id,permission_id) SELECT 3,id FROM permissions WHERE permission_key=?')->execute([$key]);
        $pdo->prepare('INSERT INTO role_permissions (role_id,permission_id) SELECT 2,id FROM permissions WHERE permission_key=?')->execute(['orders.view']);
        $pdo->exec('INSERT INTO user_roles (user_id,role_id) VALUES (1,1),(2,2),(3,3)');
        return ['owner' => 1, 'limited' => 2, 'global' => 3, 'branch' => 1];
    }
}
