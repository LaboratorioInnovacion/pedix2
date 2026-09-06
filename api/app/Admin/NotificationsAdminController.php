<?php declare(strict_types=1);
namespace VO\Admin;

use PDO; use Throwable; use VO\Audit\AuditService; use VO\Auth\AuthSession; use VO\Auth\CsrfService; use VO\Auth\PermissionGuard; use VO\Notifications\NotificationTransportFactory; use VO\Support\Template;

/**
 * Read-only notifications log (GET /admin/notificaciones; specs N3, N11 and
 * admin-shell A1): settings.manage guard (403 + audited authz.denied), a
 * best-effort dispatch sweep on load, and a newest-first listing of
 * notification_events with state/channel filters and simple pagination.
 * No resend, edit, or delete actions exist on this page.
 */
final class NotificationsAdminController
{
    private const PER_PAGE = 50;
    private const STATES = ['pending' => 'Pendiente', 'sent' => 'Enviado', 'failed' => 'Fallido'];
    private const CHANNELS = ['email' => 'Email', 'whatsapp' => 'WhatsApp'];
    private const EVENTS = [
        'order.created' => 'Pedido creado', 'order.accepted' => 'Pedido aceptado', 'order.change_approved' => 'Cambio aprobado',
        'order.rejected' => 'Pedido rechazado', 'order.in_progress' => 'En preparación', 'order.ready' => 'Listo para retiro',
        'order.completed' => 'Pedido completado', 'order.cancelled' => 'Pedido cancelado', 'payment.verified' => 'Pago verificado',
        'payment.approved' => 'Pago aprobado', 'payment.rejected' => 'Pago rechazado', 'delivery.assigned' => 'Envío asignado',
        'delivery.picked_up' => 'Pedido retirado', 'delivery.delivered' => 'Pedido entregado',
    ];

    public function __construct(private PDO $pdo,private AuthSession $sessions,private CsrfService $csrf,private Template $tpl,private AuditService $audit,private ?string $requestId) {}
    public function handle(string $method): never
    {
        if($method!=='GET')$this->notFound();
        $u=$this->user(); if(!$u)$this->redirect('/admin/login');
        if(!(new PermissionGuard($this->pdo,(int)$u['id']))->requirePermission('settings.manage'))$this->deny($u);
        $business=(int)$u['business_id'];
        try { NotificationTransportFactory::service($this->pdo,$business)->dispatchPendingLazy($business); } catch (Throwable) { /* sweep is best-effort: never block the page (spec N3) */ }
        $state=(string)($_GET['state']??''); if(!isset(self::STATES[$state]))$state='';
        $channel=(string)($_GET['channel']??''); if(!isset(self::CHANNELS[$channel]))$channel='';
        $page=max(1,(int)($_GET['page']??1)); $where='business_id=?'; $params=[$business];
        if($state!==''){$where.=' AND state=?';$params[]=$state;}
        if($channel!==''){$where.=' AND channel=?';$params[]=$channel;}
        $total=(int)$this->one("SELECT COUNT(*) c FROM notification_events WHERE $where",$params)['c'];
        $rows=$this->rows('SELECT id,event,channel,recipient,subject,state,attempts,last_error,created_at,sent_at FROM notification_events WHERE '.$where.' ORDER BY created_at DESC,id DESC LIMIT '.self::PER_PAGE.' OFFSET '.(($page-1)*self::PER_PAGE),$params);
        echo $this->tpl->render('notifications',['csrf'=>$this->token(),'user'=>$u,'rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/self::PER_PAGE)),'state'=>$state,'channel'=>$channel,'states'=>self::STATES,'channels'=>self::CHANNELS,'events'=>self::EVENTS]);exit;
    }
    private function user(): ?array { $sid=$_SESSION['auth_sid']??null;if(!is_string($sid)||$this->sessions->validate($sid)===null)return null;$s=$this->pdo->prepare('SELECT u.id,u.business_id,u.name,u.email,b.name business_name FROM users u JOIN businesses b ON b.id=u.business_id WHERE u.id=(SELECT user_id FROM auth_sessions WHERE sid_hash=? LIMIT 1) LIMIT 1');$s->execute([AuthSession::hash($sid)]);return $s->fetch(PDO::FETCH_ASSOC)?:null; }
    private function deny(array $u): never { $this->audit->append(['type'=>'user','id'=>(int)$u['id']],'authz.denied',null,['permission'=>'settings.manage'],$this->requestId);$this->error(403,'No tenés permiso para ver las notificaciones.'); }
    private function token(): string { $t=(isset($_COOKIE['vo_csrf'])&&preg_match('/^[a-f0-9]{64}$/',(string)$_COOKIE['vo_csrf']))?(string)$_COOKIE['vo_csrf']:$this->csrf->issue();$_SESSION['_csrf']=$t;if(!headers_sent())setcookie('vo_csrf',$t,['path'=>'/admin','httponly'=>true,'samesite'=>'Lax']);return $t; }
    private function rows(string $sql,array $p=[]): array { $s=$this->pdo->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC); } private function one(string $sql,array $p=[]): ?array { return $this->rows($sql,$p)[0]??null; }
    private function redirect(string $to): never { header('Location: '.$to,true,302);exit; } private function error(int $status,string $message): never { http_response_code($status);echo $this->tpl->render('catalog_error',['csrf'=>$this->token(),'message'=>$message]);exit; } private function notFound(): never { http_response_code(404);echo 'No encontrado';exit; }
}
