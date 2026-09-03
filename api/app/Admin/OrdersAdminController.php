<?php declare(strict_types=1);
namespace VO\Admin;

use PDO; use Throwable; use VO\Audit\AuditService; use VO\Auth\AuthSession; use VO\Auth\CsrfService; use VO\Auth\PermissionGuard; use VO\Database\PdoConnection; use VO\Inventory\StockService; use VO\Notifications\NotificationTransportFactory; use VO\Orders\OrderOperationsService; use VO\Orders\OrderRepository; use VO\Payments\PaymentRepository; use VO\Payments\PaymentService; use VO\Support\Template;

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
    private function listing(array $user): never { try { $this->operations()->expireStaleLazy(); } catch (Throwable) { /* sweep is best-effort */ } try { NotificationTransportFactory::service($this->pdo,(int)$user['business_id'])->dispatchPendingLazy((int)$user['business_id']); } catch (Throwable) { /* notification sweep is best-effort (spec N3) */ } $status=(string)($_GET['status'] ?? ''); $params=[(int)$user['id']]; $where='ub.user_id=?'; if ($status !== '') { $where.=' AND o.status=?'; $params[]=$status; } $orders=$this->rows("SELECT o.*,b.name branch_name FROM orders o JOIN branches b ON b.id=o.branch_id JOIN user_branches ub ON ub.branch_id=o.branch_id WHERE $where ORDER BY o.created_at DESC,o.id DESC", $params); $this->audit->append($this->actor($user),'orders.view_list',null,['status'=>$status !== '' ? $status : null],$this->requestId); echo $this->tpl->render('orders_list',['csrf'=>$this->token(),'user'=>$user,'orders'=>$orders,'status'=>$status]); exit; }
    private function detail(array $user, int $id): never { $order=$this->one('SELECT o.*,b.name branch_name FROM orders o JOIN branches b ON b.id=o.branch_id JOIN user_branches ub ON ub.branch_id=o.branch_id AND ub.user_id=? WHERE o.id=? LIMIT 1', [(int)$user['id'],$id]); if (!$order) $this->notFound(); $guard=new PermissionGuard($this->pdo,(int)$user['id']); $actions=array_values(array_filter(OperationsAdminController::actionsFor((string)$order['status']),fn(array $a): bool => $guard->requirePermission($a['permission']))); $items=$this->rows('SELECT * FROM order_items WHERE order_id=? ORDER BY id',[$id]); foreach ($items as &$i) $i['modifiers']=$this->rows('SELECT * FROM order_item_modifiers WHERE order_item_id=? ORDER BY id',[(int)$i['id']]); $delivery=$this->deliveryContext($order,$guard); [$flashOk,$flashErr]=OperationsAdminController::flashFor((string)($_GET['ok'] ?? ''),(string)($_GET['err'] ?? '')); $this->audit->append($this->actor($user),'orders.view_detail','order:'.$id,[],$this->requestId); echo $this->tpl->render('orders_detail',['csrf'=>$this->token(),'user'=>$user,'order'=>$order,'actions'=>$actions,'items'=>$items,'address'=>$this->one('SELECT * FROM order_addresses WHERE order_id=? LIMIT 1',[$id]),'discounts'=>$this->rows('SELECT * FROM order_discounts WHERE order_id=? ORDER BY id',[$id]),'movements'=>$this->rows('SELECT * FROM stock_movements WHERE order_id=? ORDER BY id',[$id]),'payment'=>$this->one('SELECT * FROM payments WHERE order_id=? LIMIT 1',[$id]),'delivery'=>$delivery,'flashOk'=>$flashOk,'flashErr'=>$flashErr]); exit; }
    /** Delivery panel context for the detail page (row + branch persons + permission-gated panel), null for pickup orders. */
    private function deliveryContext(array $order, PermissionGuard $guard): ?array
    {
        $repo=new \VO\Delivery\DeliveryRepository(new PdoConnection('',factory:fn()=> $this->pdo));
        $delivery=$repo->findByOrderId((int)$order['id']);
        if (!$delivery) return null;
        $panel=OperationsAdminController::deliveryPanel($delivery,(string)$order['status'],$guard->requirePermission('deliveries.assign'),$guard->requirePermission('deliveries.reassign'));
        $person=$delivery['delivery_person_id']!==null?$repo->person((int)$delivery['delivery_person_id']):null;
        return ['delivery'=>$delivery,'panel'=>$panel,'persons'=>$repo->personsForBranch((int)$order['branch_id']),'person_name'=>(string)($person['name'] ?? '')];
    }
    private function operations(): OrderOperationsService { $db=new PdoConnection('',factory:fn()=> $this->pdo); return new OrderOperationsService($db,new OrderRepository($db),new StockService($db),new PaymentRepository($db),new PaymentService($db,new PaymentRepository($db),new OrderRepository($db),$this->audit,$this->requestId),$this->audit,$this->requestId,new \VO\Delivery\DeliveryRepository($db)); }
    private function user(): ?array { $sid=$_SESSION['auth_sid'] ?? null; if (!is_string($sid) || $this->sessions->validate($sid) === null) return null; $s=$this->pdo->prepare('SELECT u.id,u.business_id,u.name,u.email,b.name business_name FROM users u JOIN businesses b ON b.id=u.business_id WHERE u.id=(SELECT user_id FROM auth_sessions WHERE sid_hash=? LIMIT 1) LIMIT 1'); $s->execute([AuthSession::hash($sid)]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
    private function deny(int $userId): never { $this->audit->append(['type'=>'user','id'=>$userId],'authz.denied',null,['permission'=>'products.manage'],$this->requestId); http_response_code(403); echo $this->tpl->render('catalog_error',['csrf'=>$this->token(),'message'=>'No tenés permiso para ver pedidos.']); exit; }
    private function token(): string { $t=(isset($_COOKIE['vo_csrf']) && preg_match('/^[a-f0-9]{64}$/',(string)$_COOKIE['vo_csrf'])===1) ? (string)$_COOKIE['vo_csrf'] : $this->csrf->issue(); $_SESSION['_csrf']=$t; if (!headers_sent()) setcookie('vo_csrf',$t,['path'=>'/admin','httponly'=>true,'samesite'=>'Lax']); return $t; }
    private function rows(string $sql, array $p=[]): array { $s=$this->pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function one(string $sql, array $p=[]): ?array { $r=$this->rows($sql,$p); return $r[0] ?? null; }
    private function actor(array $u): array { return ['type'=>'user','id'=>(int)$u['id']]; }
    private function redirect(string $to): never { header('Location: '.$to,true,302); exit; }
    private function notFound(): never { http_response_code(404); echo 'No encontrado'; exit; }
}
