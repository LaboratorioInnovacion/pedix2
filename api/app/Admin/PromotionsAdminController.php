<?php declare(strict_types=1);
namespace VO\Admin;

use InvalidArgumentException;
use PDO;
use VO\Audit\AuditService;
use VO\Auth\AuthSession;
use VO\Auth\CsrfService;
use VO\Auth\PermissionGuard;
use VO\Support\Template;

final class PromotionsAdminController
{
    private const TYPES = ['product_pct','product_fixed','category_pct','min_amount_pct','payment_pct','scheduled_price'];
    private const SCOPES = ['item','category','order','payment_method'];
    public function __construct(private PDO $pdo, private AuthSession $sessions, private CsrfService $csrf, private Template $tpl, private AuditService $audit, private ?string $requestId) {}

    public function handle(string $method, string $path): never
    {
        $user = $this->user();
        if ($user === null) $this->redirect('/admin/login');
        if (!(new PermissionGuard($this->pdo, (int)$user['id']))->requirePermission('products.manage')) $this->deny((int)$user['id']);
        if ($path === '/admin/promociones') $method === 'POST' ? $this->savePromotion($user, null) : $this->promotions($user);
        if (preg_match('#^/admin/promociones/(\d+)$#', $path, $m)) $method === 'POST' ? $this->savePromotion($user, (int)$m[1]) : $this->promotionForm($user, (int)$m[1]);
        if (preg_match('#^/admin/promociones/(\d+)/archivar$#', $path, $m)) { if ($method !== 'POST') $this->notFound(); $this->archivePromotion($user, (int)$m[1]); }
        if ($path === '/admin/cupones') $method === 'POST' ? $this->saveCoupon($user, null) : $this->coupons($user);
        if (preg_match('#^/admin/cupones/(\d+)$#', $path, $m)) $method === 'POST' ? $this->saveCoupon($user, (int)$m[1]) : $this->couponForm($user, (int)$m[1]);
        if (preg_match('#^/admin/cupones/(\d+)/archivar$#', $path, $m)) { if ($method !== 'POST') $this->notFound(); $this->archiveCoupon($user, (int)$m[1]); }
        $this->notFound();
    }

    private function promotions(array $user, array $errors = []): never { echo $this->tpl->render('promotions_list', ['csrf'=>$this->token(), 'user'=>$user, 'errors'=>$errors, 'promotions'=>$this->rows('SELECT * FROM promotions WHERE archived_at IS NULL ORDER BY priority,id'), 'types'=>self::TYPES, 'scopes'=>self::SCOPES]); exit; }
    private function promotionForm(array $user, int $id, array $errors = []): never { $p = $this->one('SELECT * FROM promotions WHERE id=? LIMIT 1', [$id]); if (!$p) $this->notFound(); $r = $this->one('SELECT * FROM promotion_rules WHERE promotion_id=? LIMIT 1', [$id]) ?: []; echo $this->tpl->render('promotions_form', ['csrf'=>$this->token(), 'user'=>$user, 'errors'=>$errors, 'promotion'=>$p, 'rule'=>$r, 'types'=>self::TYPES, 'scopes'=>self::SCOPES]); exit; }
    private function coupons(array $user, array $errors = []): never { echo $this->tpl->render('coupons_list', ['csrf'=>$this->token(), 'user'=>$user, 'errors'=>$errors, 'coupons'=>$this->rows('SELECT * FROM coupons WHERE archived_at IS NULL ORDER BY code')]); exit; }
    private function couponForm(array $user, int $id, array $errors = []): never { $c = $this->one('SELECT * FROM coupons WHERE id=? LIMIT 1', [$id]); if (!$c) $this->notFound(); echo $this->tpl->render('coupons_form', ['csrf'=>$this->token(), 'user'=>$user, 'errors'=>$errors, 'coupon'=>$c]); exit; }

    private function savePromotion(array $user, ?int $id): never
    {
        $this->requireCsrf();
        try {
            [$p, $r] = $this->promotionData($_POST); $this->pdo->beginTransaction();
            if ($id) { $this->exec('UPDATE promotions SET name=?,type=?,priority=?,is_stackable=?,starts_at=?,ends_at=?,usage_limit=? WHERE id=?', [$p['name'],$p['type'],$p['priority'],$p['is_stackable'],$p['starts_at'],$p['ends_at'],$p['usage_limit'],$id]); $this->exec('DELETE FROM promotion_rules WHERE promotion_id=?', [$id]); $action = 'pricing.promotion_updated'; }
            else { $this->exec('INSERT INTO promotions (name,type,priority,is_stackable,starts_at,ends_at,usage_limit) VALUES (?,?,?,?,?,?,?)', [$p['name'],$p['type'],$p['priority'],$p['is_stackable'],$p['starts_at'],$p['ends_at'],$p['usage_limit']]); $id = (int)$this->pdo->lastInsertId(); $action = 'pricing.promotion_created'; }
            $this->exec('INSERT INTO promotion_rules (promotion_id,scope,scope_id,payment_method,discount_basis_points,fixed_cents,scheduled_price_cents,min_amount_cents) VALUES (?,?,?,?,?,?,?,?)', [$id,$r['scope'],$r['scope_id'],$r['payment_method'],$r['discount_basis_points'],$r['fixed_cents'],$r['scheduled_price_cents'],$r['min_amount_cents']]);
            $this->audit->append($this->actor($user), $action, 'promotion:' . $id, ['type'=>$p['type']], $this->requestId); $this->pdo->commit(); $this->redirect('/admin/promociones');
        } catch (InvalidArgumentException $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); $id ? $this->promotionForm($user, $id, [$e->getMessage()]) : $this->promotions($user, [$e->getMessage()]); }
    }

    private function saveCoupon(array $user, ?int $id): never
    {
        $this->requireCsrf();
        try { $c = $this->couponData($_POST); if ($id) { $this->exec('UPDATE coupons SET code=?,discount_basis_points=?,min_amount_cents=?,starts_at=?,ends_at=?,usage_limit=? WHERE id=?', [$c['code'],$c['discount_basis_points'],$c['min_amount_cents'],$c['starts_at'],$c['ends_at'],$c['usage_limit'],$id]); $action='pricing.coupon_updated'; } else { $this->exec('INSERT INTO coupons (code,discount_basis_points,min_amount_cents,starts_at,ends_at,usage_limit) VALUES (?,?,?,?,?,?)', [$c['code'],$c['discount_basis_points'],$c['min_amount_cents'],$c['starts_at'],$c['ends_at'],$c['usage_limit']]); $id=(int)$this->pdo->lastInsertId(); $action='pricing.coupon_created'; } $this->audit->append($this->actor($user), $action, 'coupon:' . $id, ['code'=>$c['code']], $this->requestId); $this->redirect('/admin/cupones'); }
        catch (InvalidArgumentException $e) { $id ? $this->couponForm($user, $id, [$e->getMessage()]) : $this->coupons($user, [$e->getMessage()]); }
    }

    private function archivePromotion(array $user, int $id): never { $this->requireCsrf(); $this->exec('UPDATE promotions SET archived_at=NOW() WHERE id=? AND archived_at IS NULL', [$id]); $this->audit->append($this->actor($user), 'pricing.promotion_archived', 'promotion:' . $id, [], $this->requestId); $this->redirect('/admin/promociones'); }
    private function archiveCoupon(array $user, int $id): never { $this->requireCsrf(); $this->exec('UPDATE coupons SET archived_at=NOW() WHERE id=? AND archived_at IS NULL', [$id]); $this->audit->append($this->actor($user), 'pricing.coupon_archived', 'coupon:' . $id, [], $this->requestId); $this->redirect('/admin/cupones'); }

    private function promotionData(array $in): array
    {
        $errors = [];
        $name = trim((string)($in['name'] ?? '')); if ($name === '') $errors[] = 'El nombre es obligatorio.';
        $type = (string)($in['type'] ?? ''); if (!in_array($type, self::TYPES, true)) $errors[] = 'El tipo de promoción no es válido.';
        $scope = (string)($in['scope'] ?? ''); if (!in_array($scope, self::SCOPES, true)) $errors[] = 'El alcance no es válido.';
        if ($errors) throw new InvalidArgumentException(implode(' ', $errors));
        $rule = ['scope'=>$scope, 'scope_id'=>$this->nullableInt($in['scope_id'] ?? null), 'payment_method'=>$this->blankNull($in['payment_method'] ?? null), 'discount_basis_points'=>$this->nullableInt($in['discount_basis_points'] ?? null), 'fixed_cents'=>$this->nullableInt($in['fixed_cents'] ?? null), 'scheduled_price_cents'=>$this->nullableInt($in['scheduled_price_cents'] ?? null), 'min_amount_cents'=>$this->nullableInt($in['min_amount_cents'] ?? null)];
        if (in_array($type, ['product_pct','category_pct','min_amount_pct','payment_pct'], true) && ($rule['discount_basis_points'] ?? 0) <= 0) throw new InvalidArgumentException('El descuento en puntos básicos es obligatorio.');
        if ($type === 'product_fixed' && ($rule['fixed_cents'] ?? 0) <= 0) throw new InvalidArgumentException('El descuento fijo es obligatorio.');
        if ($type === 'scheduled_price' && ($rule['scheduled_price_cents'] ?? 0) <= 0) throw new InvalidArgumentException('El precio programado es obligatorio.');
        return [['name'=>$name, 'type'=>$type, 'priority'=>(int)($in['priority'] ?? 0), 'is_stackable'=>isset($in['is_stackable']) ? 1 : 0, 'starts_at'=>$this->blankNull($in['starts_at'] ?? null), 'ends_at'=>$this->blankNull($in['ends_at'] ?? null), 'usage_limit'=>$this->nullableInt($in['usage_limit'] ?? null)], $rule];
    }

    private function couponData(array $in): array { $code = strtoupper(trim((string)($in['code'] ?? ''))); if ($code === '') throw new InvalidArgumentException('El código es obligatorio.'); $bp = $this->nullableInt($in['discount_basis_points'] ?? null); if (($bp ?? 0) <= 0) throw new InvalidArgumentException('El descuento en puntos básicos es obligatorio.'); return ['code'=>$code, 'discount_basis_points'=>$bp, 'min_amount_cents'=>$this->nullableInt($in['min_amount_cents'] ?? null), 'starts_at'=>$this->blankNull($in['starts_at'] ?? null), 'ends_at'=>$this->blankNull($in['ends_at'] ?? null), 'usage_limit'=>$this->nullableInt($in['usage_limit'] ?? null)]; }
    private function nullableInt(mixed $v): ?int { if ($v === null || trim((string)$v) === '') return null; if (!preg_match('/^-?\d+$/', (string)$v)) throw new InvalidArgumentException('Los importes y límites deben ser números enteros.'); return (int)$v; }
    private function blankNull(mixed $v): ?string { $v = trim((string)($v ?? '')); return $v === '' ? null : $v; }
    private function user(): ?array { $sid = $_SESSION['auth_sid'] ?? null; if (!is_string($sid) || $this->sessions->validate($sid) === null) return null; $s = $this->pdo->prepare('SELECT u.id,u.name,u.email,b.name business_name FROM users u JOIN businesses b ON b.id=u.business_id WHERE u.id=(SELECT user_id FROM auth_sessions WHERE sid_hash=? LIMIT 1) LIMIT 1'); $s->execute([AuthSession::hash($sid)]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
    private function deny(int $userId): never { $this->audit->append(['type'=>'user','id'=>$userId], 'authz.denied', null, ['permission'=>'products.manage'], $this->requestId); http_response_code(403); echo $this->tpl->render('catalog_error', ['csrf'=>$this->token(), 'message'=>'No tenés permiso para administrar promociones y cupones.']); exit; }
    private function requireCsrf(): void { $t = $_POST['csrf'] ?? null; if (!$this->csrf->validate(is_string($t) ? $t : null) && !(is_string($t) && isset($_COOKIE['vo_csrf']) && hash_equals((string)$_COOKIE['vo_csrf'], $t))) { http_response_code(419); echo $this->tpl->render('catalog_error', ['csrf'=>$this->token(), 'message'=>'La solicitud venció. Volvé a intentar.']); exit; } }
    private function token(): string { $t = (isset($_COOKIE['vo_csrf']) && preg_match('/^[a-f0-9]{64}$/', (string)$_COOKIE['vo_csrf']) === 1) ? (string)$_COOKIE['vo_csrf'] : $this->csrf->issue(); $_SESSION['_csrf'] = $t; if (!headers_sent()) setcookie('vo_csrf', $t, ['path'=>'/admin','httponly'=>true,'samesite'=>'Lax']); return $t; }
    private function rows(string $sql, array $p = []): array { $s=$this->pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function one(string $sql, array $p = []): ?array { $r=$this->rows($sql,$p); return $r[0] ?? null; }
    private function exec(string $sql, array $p): void { $s=$this->pdo->prepare($sql); $s->execute($p); }
    private function actor(array $user): array { return ['type'=>'user','id'=>(int)$user['id']]; }
    private function redirect(string $to): never { header('Location: ' . $to, true, 302); exit; }
    private function notFound(): never { http_response_code(404); echo 'No encontrado'; exit; }
}
