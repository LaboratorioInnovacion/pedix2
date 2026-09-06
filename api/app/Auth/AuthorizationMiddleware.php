<?php declare(strict_types=1);
namespace VO\Auth;

use PDO;
use VO\Audit\AuditService;
use VO\Http\JsonResponse;
use VO\Http\Request;

final class AuthorizationMiddleware
{
    public function __construct(private PDO $pdo, private mixed $audit = null) {}

    public function authorize(Request $request, int $userId, string $permission, ?int $branchId, callable $next): JsonResponse
    {
        $allowed = (new PermissionGuard($this->pdo, $userId))->requirePermission($permission);
        if ($allowed && $branchId !== null) $allowed = (new BranchScope($this->pdo))->requireBranch($userId, $branchId);
        if ($allowed) return $next($request);

        $requestId = $request->attribute('request_id');
        $this->auditDenied($userId, $permission, $branchId, is_string($requestId) ? $requestId : null);
        return JsonResponse::error('forbidden', 'Forbidden.', 403, is_string($requestId) ? $requestId : null);
    }

    private function auditDenied(int $userId, string $permission, ?int $branchId, ?string $requestId): void
    {
        $metadata = ['permission' => $permission, 'branch_id' => $branchId];
        if (is_callable($this->audit)) {
            ($this->audit)(['action' => 'authz.denied', 'actor_id' => $userId, 'request_id' => $requestId, 'metadata' => $metadata]);
            return;
        }
        $service = $this->audit instanceof AuditService ? $this->audit : new AuditService($this->pdo);
        $service->append(['type' => 'user', 'id' => $userId], 'authz.denied', null, $metadata, $requestId);
    }
}
