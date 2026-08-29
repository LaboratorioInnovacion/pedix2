<?php declare(strict_types=1);
namespace VO\Admin;

use PDO; use VO\Audit\AuditService; use VO\Auth\AuthSession; use VO\Auth\CsrfService; use VO\Auth\PermissionGuard; use VO\Support\Template;

final class OrdersAdminController
{
    public function __construct(private PDO $pdo, private AuthSession $sessions, private CsrfService $csrf, private Template $tpl, private AuditService $audit, private ?string $requestId) {}
    public function handle(string $method, string $path): never
    {
        $user = $this->user(); if ($user === null) $this->redirect('/admin/login');
        if (!(new PermissionGuard($this->pdo, (int)$user['id']))->requirePermission('products.manage')) $this->deny((int)$user['id']);
        if ($method === 'GET' && $path === '/admin/pedidos') $this->listing($user);
        if ($method === 'GET' && preg_match('#^/admin/pedidos/(\d+)$#', $path, $m)) $this->detail($user, (int)$m[1]);
        $this->notFound();
    }
    private function listing(array $user): never { $status=(string)($_GET['status'] ?? ''); $params=[(int)$user['id']]; $where='ub.user_id=?'; if ($status !== '') { $where.=' AND o.status=?'; $params[]=$status; } $orders=$this->rows("SELECT o.*,b.name branch_name FROM orders o JOIN branches b ON b.id=o.branch_id JOIN user_branches ub ON ub.branch_id=o.branch_id WHERE $where ORDER BY o.created_at DESC,o.id DESC", $params); $this->audit->append($this->actor($user),'orders.view_list',null,['status'=>$status !== '' ? $status : null],$this->requestId); echo $this->tpl->render('orders_list',['csrf'=>$this->token(),'user'=>$user,'orders'=>$orders,'status'=>$status]); exit; }
    private function detail(array $user, int $id): never { $order=$this->one('SELECT o.*,b.name branch_name FROM orders o JOIN branches b ON b.id=o.branch_id JOIN user_branches ub ON ub.branch_id=o.branch_id AND ub.user_id=? WHERE o.id=? LIMIT 1', [(int)$user['id'],$id]); if (!$order) $this->notFound(); $items=$this->rows('SELECT * FROM order_items WHERE order_id=? ORDER BY id',[$id]); foreach ($items as &$i) $i['modifiers']=$this->rows('SELECT * FROM order_item_modifiers WHERE order_item_id=? ORDER BY id',[(int)$i['id']]); $this->audit->append($this->actor($user),'orders.view_detail','order:'.$id,[],$this->requestId); echo $this->tpl->render('orders_detail',['csrf'=>$this->token(),'user'=>$user,'order'=>$order,'items'=>$items,'address'=>$this->one('SELECT * FROM order_addresses WHERE order_id=? LIMIT 1',[$id]),'discounts'=>$this->rows('SELECT * FROM order_discounts WHERE order_id=? ORDER BY id',[$id]),'movements'=>$this->rows('SELECT * FROM stock_movements WHERE order_id=? ORDER BY id',[$id])]); exit; }
    private function user(): ?array { $sid=$_SESSION['auth_sid'] ?? null; if (!is_string($sid) || $this->sessions->validate($sid) === null) return null; $s=$this->pdo->prepare('SELECT u.id,u.name,u.email,b.name business_name FROM users u JOIN businesses b ON b.id=u.business_id WHERE u.id=(SELECT user_id FROM auth_sessions WHERE sid_hash=? LIMIT 1) LIMIT 1'); $s->execute([AuthSession::hash($sid)]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
    private function deny(int $userId): never { $this->audit->append(['type'=>'user','id'=>$userId],'authz.denied',null,['permission'=>'products.manage'],$this->requestId); http_response_code(403); echo $this->tpl->render('catalog_error',['csrf'=>$this->token(),'message'=>'No tenés permiso para ver pedidos.']); exit; }
    private function token(): string { $t=(isset($_COOKIE['vo_csrf']) && preg_match('/^[a-f0-9]{64}$/',(string)$_COOKIE['vo_csrf'])===1) ? (string)$_COOKIE['vo_csrf'] : $this->csrf->issue(); $_SESSION['_csrf']=$t; if (!headers_sent()) setcookie('vo_csrf',$t,['path'=>'/admin','httponly'=>true,'samesite'=>'Lax']); return $t; }
    private function rows(string $sql, array $p=[]): array { $s=$this->pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function one(string $sql, array $p=[]): ?array { $r=$this->rows($sql,$p); return $r[0] ?? null; }
    private function actor(array $u): array { return ['type'=>'user','id'=>(int)$u['id']]; }
    private function redirect(string $to): never { header('Location: '.$to,true,302); exit; }
    private function notFound(): never { http_response_code(404); echo 'No encontrado'; exit; }
}
