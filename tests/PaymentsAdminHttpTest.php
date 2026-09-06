<?php declare(strict_types=1);
namespace Tests;

use PDO; use Throwable; use VO\Database\MigrationRunner; use VO\Database\PdoConnection; use VO\Installer\InstallerSeeder;

final class PaymentsAdminHttpTest extends TestCase
{
    public function testPublicTransferSectionValidatesAndStoresPrivateRandomProof(): void
    {
        $s=PaymentsAdminScratchDatabase::create(); [$h,$storage]=$this->harness($s,'proof');
        try {
            $token=$s->order('transfer','B-000001'); $s->setting('transfer_instructions','Alias: DEMO.PAGO');
            $page=$h->request('GET','/pedido/'.$token); $this->assertSame(200,$page['status']); foreach(['Pago','Pendiente de verificación','Alias: DEMO.PAGO','multipart/form-data','/comprobante'] as $n)$this->assertTrue(str_contains($page['body'],$n),$n);
            $bad=tempnam(sys_get_temp_dir(),'badproof_'); file_put_contents($bad,'not an image'); $r=$h->upload('/pedido/'.$token.'/comprobante',[],'proof',$bad,'receipt.jpg'); $this->assertSame(422,$r['status']); $this->assertSame(null,$s->pdo->query('SELECT proof_path FROM payments LIMIT 1')->fetchColumn());
            $large=tempnam(sys_get_temp_dir(),'largeproof_'); file_put_contents($large,str_repeat('x',5242881)); $this->assertSame(422,$h->upload('/pedido/'.$token.'/comprobante',[],'proof',$large,'receipt.pdf')['status']);
            $png=tempnam(sys_get_temp_dir(),'proof_'); file_put_contents($png,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')); $ok=$h->upload('/pedido/'.$token.'/comprobante',[],'proof',$png,'customer-name.png'); $this->assertSame(200,$ok['status']);
            $path=(string)$s->pdo->query('SELECT proof_path FROM payments LIMIT 1')->fetchColumn(); $this->assertTrue((bool)preg_match('#^proofs/1/B-000001/[a-f0-9]{64}\.png$#',$path),$path); $this->assertTrue(is_file($storage.'/'.$path)); $this->assertTrue(!str_contains($path,'customer-name')&&!str_contains($page['body'],'/storage/'));
            @unlink($bad);@unlink($large);@unlink($png);
        } finally {$h->stop();$s->drop();}
    }

    public function testAdminGuardFiltersVerifyRejectAuditAndProtectedDownload(): void
    {
        $s=PaymentsAdminScratchDatabase::create(); [$h,$storage]=$this->harness($s,'admin');
        try {
            $one=$s->payment('transfer','B-000001'); $two=$s->payment('transfer','B-000002'); $proof='proofs/1/B-000001/'.str_repeat('a',64).'.pdf'; @mkdir(dirname($storage.'/'.$proof),0700,true); file_put_contents($storage.'/'.$proof,"%PDF-1.4\nproof"); $s->pdo->exec("UPDATE payments SET proof_path=".$s->pdo->quote($proof)." WHERE id=".$one['payment']);
            $this->login($h); $list=$h->request('GET','/admin/pagos?state=pending_verification&method=transfer'); $this->assertSame(200,$list['status']); foreach(['Pagos','B-000001','B-000002','Transferencia'] as $n)$this->assertTrue(str_contains($list['body'],$n),$n);
            $detail=$h->request('GET','/admin/pagos/'.$one['payment']); $csrf=$this->csrf($detail['body']); $this->assertTrue(str_contains($detail['body'],'Verificar transferencia'));
            $s->removePermission('payments.verify_transfer'); $deny=$h->request('POST','/admin/pagos/'.$one['payment'].'/verificar',['csrf'=>$csrf]); $this->assertSame(403,$deny['status']); $this->assertSame('pending_verification',$s->state($one['payment']));
            $s->grantPermission('payments.verify_transfer'); $download=$h->request('GET','/admin/pagos/'.$one['payment'].'/comprobante'); $this->assertSame(200,$download['status']); $this->assertTrue(str_contains($download['body'],'%PDF-1.4'));
            $this->assertSame(302,$h->request('POST','/admin/pagos/'.$one['payment'].'/verificar',['csrf'=>$csrf])['status']); $this->assertSame('verified',$s->state($one['payment'])); $this->assertSame('accepted',$s->orderState($one['order']));
            $this->assertSame(302,$h->request('POST','/admin/pagos/'.$two['payment'].'/rechazar',['csrf'=>$csrf,'reason'=>'No coincide'])['status']); $this->assertSame('rejected',$s->state($two['payment'])); $this->assertSame('pending',$s->orderState($two['order']));
            $this->assertSame(2,(int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('payment.verified','payment.rejected') AND request_id IS NOT NULL")->fetchColumn()); $this->assertTrue((int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='authz.denied' AND request_id IS NOT NULL")->fetchColumn()>=1);
        } finally {$h->stop();$s->drop();}
    }

    public function testSettingsPermissionAndSecretsNeverRenderOrAudit(): void
    {
        $s=PaymentsAdminScratchDatabase::create(); [$h]=$this->harness($s,'settings');
        try {
            $s->setting('mp_access_token','OLD_PRIVATE_TOKEN');$s->setting('mp_webhook_secret','OLD_PRIVATE_SECRET');$this->login($h);$s->removePermission('settings.manage');$this->assertSame(403,$h->request('GET','/admin/configuracion')['status']);$s->grantPermission('settings.manage');
            $page=$h->request('GET','/admin/configuracion');$this->assertSame(200,$page['status']);$this->assertTrue(!str_contains($page['body'],'OLD_PRIVATE_TOKEN')&&!str_contains($page['body'],'OLD_PRIVATE_SECRET'));$csrf=$this->csrf($page['body']);
            $r=$h->request('POST','/admin/configuracion',['csrf'=>$csrf,'mp_enabled'=>'1','mp_access_token'=>'NEW_PRIVATE_TOKEN','mp_webhook_secret'=>'NEW_PRIVATE_SECRET','transfer_instructions'=>'Alias seguro']);$this->assertSame(302,$r['status']);
            $this->assertSame('1',$s->settingValue('mp_enabled'));$this->assertSame('NEW_PRIVATE_TOKEN',$s->settingValue('mp_access_token'));$this->assertSame('Alias seguro',$s->settingValue('transfer_instructions'));
            $blob=(string)$s->pdo->query("SELECT GROUP_CONCAT(metadata_json) FROM audit_log WHERE action='settings.updated'")->fetchColumn();$this->assertTrue(!str_contains($blob,'NEW_PRIVATE_TOKEN')&&!str_contains($blob,'NEW_PRIVATE_SECRET'));$again=$h->request('GET','/admin/configuracion')['body'];$this->assertTrue(!str_contains($again,'NEW_PRIVATE_TOKEN')&&!str_contains($again,'NEW_PRIVATE_SECRET'));
        } finally {$h->stop();$s->drop();}
    }

    public function testPublicMercadoPagoStateAndPayButton(): void
    {
        $s=PaymentsAdminScratchDatabase::create(); [$h]=$this->harness($s,'mp');
        try {$token=$s->order('mercadopago','B-000010');$id=(int)$s->pdo->query('SELECT id FROM orders ORDER BY id DESC LIMIT 1')->fetchColumn();$s->pdo->exec("INSERT INTO payments (order_id,business_id,method,state,amount_cents,preference_init_point) VALUES ($id,1,'mercadopago','pending',1000,'https://pay.example/secure')");$page=$h->request('GET','/pedido/'.$token);$this->assertSame(200,$page['status']);foreach(['Pago','Pendiente','Pagar con Mercado Pago','https://pay.example/secure'] as $n)$this->assertTrue(str_contains($page['body'],$n),$n);} finally {$h->stop();$s->drop();}
    }
    private function harness(PaymentsAdminScratchDatabase $s,string $name): array {$dir=$this->tempDir('payments_'.$name);$lock=$dir.'/installed.php';$s->install($lock);$storage=$dir.'/storage';$h=new \InstallerTestServer(dirname(__DIR__).'/public_html',['VO_INSTALLED_CONFIG_PATH'=>$lock,'VO_STORAGE_PATH'=>$storage,'VO_HTTP_NO_REDIRECTS'=>'1']);$h->start();return[$h,$storage];}
    private function login(\InstallerTestServer $h): void {$csrf=$this->csrf($h->request('GET','/admin/login')['body']);$this->assertSame(302,$h->request('POST','/admin/login',['csrf'=>$csrf,'email'=>'owner@example.test','password'=>'Password123'])['status']);}
    private function csrf(string $html): string {if(!preg_match('/name="csrf" value="([^"]+)"/',$html,$m))throw new \RuntimeException('Missing CSRF');return $m[1];}
}

final class PaymentsAdminScratchDatabase
{
    private function __construct(public string $name,public PDO $pdo,private PDO $server){}
    public static function create(): self {try{$server=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}catch(Throwable $e){throw new \RuntimeException('MariaDB is REQUIRED for PaymentsAdminHttpTest.',0,$e);}$name='vo_payadmin_'.bin2hex(random_bytes(5));$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$pdo=new PDO("mysql:host=127.0.0.1;port=3306;dbname=$name;charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);return new self($name,$pdo,$server);}
    public function install(string $lock): void {$db=new PdoConnection("mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4",'root','');(new MigrationRunner($db,dirname(__DIR__).'/api/database/migrations'))->run();(new InstallerSeeder($db))->seed(['business_name'=>'Demo','business_slug'=>'demo','branch_name'=>'Centro','timezone'=>'UTC','admin_name'=>'Owner','admin_email'=>'owner@example.test','admin_password'=>'Password123']);if(!is_dir(dirname($lock)))mkdir(dirname($lock),0777,true);file_put_contents($lock,'<?php return '.var_export(['database'=>['dsn'=>"mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4",'user'=>'root','password'=>'']],true).';');}
    public function order(string $method,string $number): string {$token=bin2hex(random_bytes(32));$branch=(int)$this->pdo->query('SELECT id FROM branches LIMIT 1')->fetchColumn();$s=$this->pdo->prepare("INSERT INTO orders (business_id,branch_id,number,status,public_token,fulfillment,payment_method,gross_items_cents,item_promotions_cents,order_promotions_cents,coupon_discount_cents,payment_discount_cents,merchandise_total_cents,delivery_fee_cents,grand_total_cents) VALUES (1,? ,?,'pending',?,'pickup',?,1000,0,0,0,0,1000,0,1000)");$s->execute([$branch,$number,$token,$method]);return $token;}
    public function payment(string $method,string $number): array {$this->order($method,$number);$order=(int)$this->pdo->lastInsertId();$state=$method==='transfer'?'pending_verification':'pending';$this->pdo->prepare('INSERT INTO payments (order_id,business_id,method,state,amount_cents) VALUES (?,?,?,?,1000)')->execute([$order,1,$method,$state]);return['order'=>$order,'payment'=>(int)$this->pdo->lastInsertId()];}
    public function setting(string $key,string $value): void {$this->pdo->prepare('INSERT INTO business_settings (business_id,setting_key,setting_value) VALUES (1,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,$value]);} public function settingValue(string $key): string {$s=$this->pdo->prepare('SELECT setting_value FROM business_settings WHERE business_id=1 AND setting_key=?');$s->execute([$key]);return(string)$s->fetchColumn();}
    public function removePermission(string $key): void {$this->pdo->prepare('DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_key=?')->execute([$key]);} public function grantPermission(string $key): void {$this->pdo->prepare('INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key=? LIMIT 1')->execute([$key]);}
    public function state(int $id): string {return(string)$this->pdo->query('SELECT state FROM payments WHERE id='.$id)->fetchColumn();} public function orderState(int $id): string {return(string)$this->pdo->query('SELECT status FROM orders WHERE id='.$id)->fetchColumn();} public function drop(): void {$this->server->exec("DROP DATABASE IF EXISTS `$this->name`");}
}
