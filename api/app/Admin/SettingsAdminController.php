<?php declare(strict_types=1);
namespace VO\Admin;

use PDO; use VO\Audit\AuditService; use VO\Auth\AuthSession; use VO\Auth\CsrfService; use VO\Auth\PermissionGuard; use VO\Settings\SettingsRepository; use VO\Support\Template;

final class SettingsAdminController
{
    public function __construct(private PDO $pdo,private AuthSession $sessions,private CsrfService $csrf,private Template $tpl,private AuditService $audit,private ?string $requestId) {}
    public function handle(string $method): never
    {
        $u=$this->user(); if(!$u)$this->redirect('/admin/login'); if(!(new PermissionGuard($this->pdo,(int)$u['id']))->requirePermission('settings.manage'))$this->deny($u);
        $repo=new SettingsRepository($this->pdo); $business=(int)$u['business_id'];
        if($method==='POST') {
            if(!$this->validCsrf($_POST['csrf']??null))$this->error(419,'La solicitud venció.');
            $port=trim((string)($_POST['smtp_port']??'')); $fromEmail=trim((string)($_POST['smtp_from_email']??'')); // validate BEFORE saving anything
            if($port!==''&&(filter_var($port,FILTER_VALIDATE_INT)===false||(int)$port<1||(int)$port>65535))$this->error(422,'El puerto SMTP debe ser un número entre 1 y 65535.');
            if($fromEmail!==''&&!filter_var($fromEmail,FILTER_VALIDATE_EMAIL))$this->error(422,'El email del remitente no es válido.');
            $keys=['mp_enabled','transfer_instructions','notifications_enabled','notifications_email_enabled','notifications_whatsapp_enabled'];
            $repo->set($business,'mp_enabled',isset($_POST['mp_enabled'])?'1':'0'); $repo->set($business,'transfer_instructions',substr((string)($_POST['transfer_instructions']??''),0,5000));
            foreach(['mp_access_token','mp_webhook_secret'] as $key)if((string)($_POST[$key]??'')!==''){$repo->set($business,$key,(string)$_POST[$key]);$keys[]=$key;}
            foreach(['notifications_enabled','notifications_email_enabled','notifications_whatsapp_enabled'] as $key)$repo->set($business,$key,isset($_POST[$key])?'1':'0');
            foreach(['smtp_host','smtp_port','smtp_username','smtp_from_email','smtp_from_name','whatsapp_bridge_url'] as $key)$repo->set($business,$key,trim((string)($_POST[$key]??'')));
            foreach(['smtp_password','whatsapp_bridge_token'] as $key)if((string)($_POST[$key]??'')!==''){$repo->set($business,$key,(string)$_POST[$key]);$keys[]=$key;} // write-only: empty input keeps the stored value, never echoed/audited (spec N9)
            $this->audit->append(['type'=>'user','id'=>(int)$u['id']],'settings.updated','business:'.$business,['changed_keys'=>$keys],$this->requestId); $this->redirect('/admin/configuracion'); }
        echo $this->tpl->render('settings',['csrf'=>$this->token(),'user'=>$u,'enabled'=>$repo->get($business,'mp_enabled')==='1','instructions'=>$repo->get($business,'transfer_instructions')??'','notif'=>[
            'master'=>$repo->get($business,'notifications_enabled')==='1',
            'email'=>$repo->get($business,'notifications_email_enabled')==='1',
            'whatsapp'=>$repo->get($business,'notifications_whatsapp_enabled')==='1',
            'smtp_host'=>(string)$repo->get($business,'smtp_host'),
            'smtp_port'=>(string)$repo->get($business,'smtp_port'),
            'smtp_username'=>(string)$repo->get($business,'smtp_username'),
            'smtp_from_email'=>(string)$repo->get($business,'smtp_from_email'),
            'smtp_from_name'=>(string)$repo->get($business,'smtp_from_name'),
            'whatsapp_bridge_url'=>(string)$repo->get($business,'whatsapp_bridge_url'),
        ]]);exit;
    }
    private function user(): ?array { $sid=$_SESSION['auth_sid']??null;if(!is_string($sid)||$this->sessions->validate($sid)===null)return null;$s=$this->pdo->prepare('SELECT u.id,u.business_id,u.name,u.email,b.name business_name FROM users u JOIN businesses b ON b.id=u.business_id WHERE u.id=(SELECT user_id FROM auth_sessions WHERE sid_hash=? LIMIT 1) LIMIT 1');$s->execute([AuthSession::hash($sid)]);return $s->fetch(PDO::FETCH_ASSOC)?:null; }
    private function deny(array $u): never { $this->audit->append(['type'=>'user','id'=>(int)$u['id']],'authz.denied',null,['permission'=>'settings.manage'],$this->requestId);$this->error(403,'No tenés permiso para administrar la configuración.'); }
    private function token(): string { $t=(isset($_COOKIE['vo_csrf'])&&preg_match('/^[a-f0-9]{64}$/',(string)$_COOKIE['vo_csrf']))?(string)$_COOKIE['vo_csrf']:$this->csrf->issue();$_SESSION['_csrf']=$t;if(!headers_sent())setcookie('vo_csrf',$t,['path'=>'/admin','httponly'=>true,'samesite'=>'Lax']);return $t; }
    private function validCsrf(mixed $v): bool{return $this->csrf->validate(is_string($v)?$v:null)||(is_string($v)&&isset($_COOKIE['vo_csrf'])&&hash_equals((string)$_COOKIE['vo_csrf'],$v));} private function redirect(string $to): never{header('Location: '.$to,true,302);exit;} private function error(int $status,string $message): never{http_response_code($status);echo $this->tpl->render('catalog_error',['csrf'=>$this->token(),'message'=>$message]);exit;}
}
