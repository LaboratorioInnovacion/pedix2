<?php declare(strict_types=1);
namespace VO\Admin;

use PDO; use Throwable; use VO\Audit\AuditService; use VO\Auth\AuthSession; use VO\Auth\CsrfService; use VO\Auth\PermissionGuard; use VO\Database\PdoConnection; use VO\Orders\OrderRepository; use VO\Payments\PaymentRepository; use VO\Payments\PaymentService; use VO\Payments\ProofStorage; use VO\Support\Template;

final class PaymentsAdminController
{
    public function __construct(private PDO $pdo, private AuthSession $sessions, private CsrfService $csrf, private Template $tpl, private AuditService $audit, private ?string $requestId, private string $storageRoot) {}
    public function handle(string $method,string $path): never
    {
        $u=$this->user(); if (!$u) $this->redirect('/admin/login'); $guard=new PermissionGuard($this->pdo,(int)$u['id']);
        if ($method==='GET' && $path==='/admin/pagos') { if (!$guard->requirePermission('products.manage')) $this->deny($u,'products.manage'); $this->listing($u); }
        if ($method==='GET' && preg_match('#^/admin/pagos/(\d+)$#',$path,$m)) { if (!$guard->requirePermission('products.manage')) $this->deny($u,'products.manage'); $this->detail($u,(int)$m[1]); }
        if ($method==='GET' && preg_match('#^/admin/pagos/(\d+)/comprobante$#',$path,$m)) { if (!$guard->requirePermission('payments.verify_transfer')) $this->deny($u,'payments.verify_transfer'); $this->proof($u,(int)$m[1]); }
        if ($method==='POST' && preg_match('#^/admin/pagos/(\d+)/(verificar|rechazar)$#',$path,$m)) { if (!$guard->requirePermission('payments.verify_transfer')) $this->deny($u,'payments.verify_transfer'); $this->action($u,(int)$m[1],$m[2]); }
        $this->notFound();
    }
    private function listing(array $u): never
    {
        $state=(string)($_GET['state']??''); $method=(string)($_GET['method']??''); $p=[(int)$u['id']]; $where='ub.user_id=?';
        if (in_array($state,['pending','approved','rejected','cancelled','pending_verification','verified'],true)) { $where.=' AND p.state=?'; $p[]=$state; } else $state='';
        if (in_array($method,['transfer','mercadopago'],true)) { $where.=' AND p.method=?'; $p[]=$method; } else $method='';
        $rows=$this->rows("SELECT p.*,o.number order_number,b.name branch_name FROM payments p JOIN orders o ON o.id=p.order_id JOIN branches b ON b.id=o.branch_id JOIN user_branches ub ON ub.branch_id=o.branch_id WHERE $where ORDER BY p.created_at DESC,p.id DESC",$p);
        echo $this->tpl->render('payments_list',['csrf'=>$this->token(),'user'=>$u,'payments'=>$rows,'state'=>$state,'method'=>$method]); exit;
    }
    private function detail(array $u,int $id): never { $p=$this->scoped($u,$id); if (!$p) $this->notFound(); echo $this->tpl->render('payments_detail',['csrf'=>$this->token(),'user'=>$u,'payment'=>$p]); exit; }
    private function action(array $u,int $id,string $action): never
    {
        if (!$this->validCsrf($_POST['csrf']??null)) $this->error(419,'La solicitud venció.'); if (!$this->scoped($u,$id)) $this->notFound();
        $db=new PdoConnection('',factory:fn()=> $this->pdo); $service=new PaymentService($db,new PaymentRepository($db),new OrderRepository($db),$this->audit,$this->requestId);
        try { $action==='verificar' ? $service->verifyTransfer($id,(int)$u['id']) : $service->rejectPayment($id,(int)$u['id'],substr((string)($_POST['reason']??'Rechazado'),0,191)); }
        catch (Throwable) { $this->error(409,'El pago ya no admite esa acción.'); }
        $this->redirect('/admin/pagos/'.$id);
    }
    private function proof(array $u,int $id): never
    {
        $p=$this->scoped($u,$id); if (!$p || empty($p['proof_path'])) $this->notFound(); $file=(new ProofStorage($this->storageRoot))->absolute((string)$p['proof_path']); if (!$file) $this->notFound();
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream'; header('Content-Type: '.$mime); header('Content-Length: '.filesize($file)); header('Content-Disposition: inline; filename="comprobante-'.(int)$id.'.'.pathinfo($file,PATHINFO_EXTENSION).'"'); readfile($file); exit;
    }
    private function scoped(array $u,int $id): ?array { return $this->one('SELECT p.*,o.number order_number,o.status order_status,b.name branch_name FROM payments p JOIN orders o ON o.id=p.order_id JOIN branches b ON b.id=o.branch_id JOIN user_branches ub ON ub.branch_id=o.branch_id AND ub.user_id=? WHERE p.id=? LIMIT 1',[(int)$u['id'],$id]); }
    private function user(): ?array { $sid=$_SESSION['auth_sid']??null; if (!is_string($sid)||$this->sessions->validate($sid)===null)return null; return $this->one('SELECT u.id,u.business_id,u.name,u.email,b.name business_name FROM users u JOIN businesses b ON b.id=u.business_id WHERE u.id=(SELECT user_id FROM auth_sessions WHERE sid_hash=? LIMIT 1) LIMIT 1',[AuthSession::hash($sid)]); }
    private function deny(array $u,string $permission): never { $this->audit->append(['type'=>'user','id'=>(int)$u['id']],'authz.denied',null,['permission'=>$permission],$this->requestId); $this->error(403,'No tenés permiso para esta acción.'); }
    private function token(): string { $t=(isset($_COOKIE['vo_csrf'])&&preg_match('/^[a-f0-9]{64}$/',(string)$_COOKIE['vo_csrf']))?(string)$_COOKIE['vo_csrf']:$this->csrf->issue(); $_SESSION['_csrf']=$t; if(!headers_sent())setcookie('vo_csrf',$t,['path'=>'/admin','httponly'=>true,'samesite'=>'Lax']); return $t; }
    private function validCsrf(mixed $v): bool { return $this->csrf->validate(is_string($v)?$v:null)||(is_string($v)&&isset($_COOKIE['vo_csrf'])&&hash_equals((string)$_COOKIE['vo_csrf'],$v)); }
    private function rows(string $q,array $p=[]): array { $s=$this->pdo->prepare($q);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC); } private function one(string $q,array $p=[]): ?array { return $this->rows($q,$p)[0]??null; }
    private function redirect(string $to): never { header('Location: '.$to,true,302);exit; } private function error(int $status,string $message): never { http_response_code($status);echo $this->tpl->render('catalog_error',['csrf'=>$this->token(),'message'=>$message]);exit; } private function notFound(): never { http_response_code(404);echo 'No encontrado';exit; }
}
