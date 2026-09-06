<?php declare(strict_types=1);
namespace VO\Admin;

use PDO; use VO\Audit\AuditService; use VO\Auth\AuthSession; use VO\Auth\CsrfService; use VO\Auth\PermissionGuard; use VO\Database\PdoConnection; use VO\Reports\ReportsRepository; use VO\Reports\ReportsService; use VO\Support\Template;

/**
 * Ventas report (GET /admin/reportes + /admin/reportes/csv; specs reports R1–R7):
 * auth redirect, reports.view guard (403 + audited authz.denied), service-parsed
 * filters (presets + custom, invalid values silently ignored), six integer-cents
 * metrics with daily breakdown, and a UTF-8 BOM semicolon CSV stream under the same
 * guard and filters. Every query is branch-scoped via user_branches (R7).
 */
final class ReportsAdminController
{
    private const STATUS_LABELS = ['pending' => 'Pendiente', 'change_proposed' => 'Cambio propuesto', 'accepted' => 'Aceptado', 'in_progress' => 'En preparación', 'ready' => 'Listo', 'completed' => 'Completado', 'rejected' => 'Rechazado', 'cancelled' => 'Cancelado', 'expired' => 'Expirado'];

    public function __construct(private PDO $pdo, private AuthSession $sessions, private CsrfService $csrf, private Template $tpl, private AuditService $audit, private ?string $requestId) {}

    public function handle(string $method, string $path): never
    {
        if ($method !== 'GET') $this->notFound();
        $user = $this->user();
        if ($user === null) $this->redirect('/admin/login');
        if (!(new PermissionGuard($this->pdo, (int)$user['id']))->requirePermission('reports.view')) $this->deny((int)$user['id']);
        $service = $this->service();
        if ($path === '/admin/reportes/csv') $this->csv($service, (int)$user['id']);
        if ($path === '/admin/reportes') $this->page($service, $user);
        $this->notFound();
    }

    /** Report page (R1): filter form, six metric cards, daily table and a CSV link carrying the effective filters. */
    private function page(ReportsService $service, array $user): never
    {
        $filters = $service->parseFilters($_GET, (int)$user['id']);
        $report = $service->report($filters, (int)$user['id']);
        echo $this->tpl->render('reportes', [
            'csrf' => $this->token(), 'user' => $user, 'filters' => $filters,
            'rows' => $report['rows'], 'totals' => $report['totals'],
            'branches' => $service->branchesFor((int)$user['id']),
            'statuses' => self::STATUS_LABELS, 'labels' => ReportsService::METRIC_LABELS,
            'csvHref' => '/admin/reportes/csv' . $this->carryQuery($filters),
        ]);
        exit;
    }

    /**
     * CSV export (R6): text/csv; charset=utf-8, UTF-8 BOM, semicolon separator, Spanish
     * header row from the METRIC_LABELS contract, one row per day plus a TOTAL row, and
     * the ventas_<from>_<to>.csv attachment filename. No layout — the body is the stream.
     */
    private function csv(ReportsService $service, int $userId): never
    {
        $filters = $service->parseFilters($_GET, $userId);
        $report = $service->report($filters, $userId);
        // Rows are joined manually: fputcsv would quote space-containing fields on this
        // PHP build, breaking the exact METRIC_LABELS header contract. Fields are dates,
        // integers and money() strings — none can ever contain ';' or a newline.
        $lines = ['Fecha;' . implode(';', array_values(ReportsService::METRIC_LABELS))];
        foreach ($report['rows'] as $r) {
            $lines[] = implode(';', [$r['date'], $r['orders'], ReportsService::money((int)$r['bruta']), ReportsService::money((int)$r['descuentos']), ReportsService::money((int)$r['delivery_cobrado']), ReportsService::money((int)$r['remuneracion']), ReportsService::money((int)$r['neta'])]);
        }
        $t = $report['totals'];
        $lines[] = implode(';', ['TOTAL', $t['orders'], ReportsService::money($t['bruta']), ReportsService::money($t['descuentos']), ReportsService::money($t['delivery_cobrado']), ReportsService::money($t['remuneracion']), ReportsService::money($t['neta'])]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ventas_' . str_replace('-', '', $filters['from']) . '-' . str_replace('-', '', $filters['to']) . '.csv"');
        echo "\xEF\xBB\xBF" . implode("\n", $lines) . "\n";
        exit;
    }

    /** Normalized filter set as a query string: the CSV link preserves exactly the effective filters. */
    private function carryQuery(array $f): string
    {
        $qs = http_build_query(array_filter(['preset' => $f['preset'], 'from' => $f['from'], 'to' => $f['to'], 'branch_id' => $f['branchId'], 'producto' => $f['productId'], 'categoria' => $f['categoryId'], 'medio' => $f['paymentMethod'], 'modalidad' => $f['fulfillment'], 'estado' => $f['status']], static fn (mixed $v): bool => $v !== null));
        return $qs !== '' ? '?' . $qs : '';
    }

    private function service(): ReportsService
    {
        $db = new PdoConnection('', factory: fn() => $this->pdo);
        return new ReportsService(new ReportsRepository($db), $db);
    }
    private function user(): ?array { $sid = $_SESSION['auth_sid'] ?? null; if (!is_string($sid) || $this->sessions->validate($sid) === null) return null; $s = $this->pdo->prepare('SELECT u.id,u.business_id,u.name,u.email,b.name business_name FROM users u JOIN businesses b ON b.id=u.business_id WHERE u.id=(SELECT user_id FROM auth_sessions WHERE sid_hash=? LIMIT 1) LIMIT 1'); $s->execute([AuthSession::hash($sid)]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
    private function deny(int $userId): never { $this->audit->append(['type' => 'user', 'id' => $userId], 'authz.denied', null, ['permission' => 'reports.view'], $this->requestId); http_response_code(403); echo $this->tpl->render('catalog_error', ['csrf' => $this->token(), 'message' => 'No tenés permiso para ver los reportes.']); exit; }
    private function token(): string { $t = (isset($_COOKIE['vo_csrf']) && preg_match('/^[a-f0-9]{64}$/', (string)$_COOKIE['vo_csrf']) === 1) ? (string)$_COOKIE['vo_csrf'] : $this->csrf->issue(); $_SESSION['_csrf'] = $t; if (!headers_sent()) setcookie('vo_csrf', $t, ['path' => '/admin', 'httponly' => true, 'samesite' => 'Lax']); return $t; }
    private function redirect(string $to): never { header('Location: ' . $to, true, 302); exit; }
    private function notFound(): never { http_response_code(404); echo 'No encontrado'; exit; }
}
