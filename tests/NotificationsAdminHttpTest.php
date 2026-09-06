<?php declare(strict_types=1);
namespace Tests;

use PDO; use Throwable; use VO\Database\MigrationRunner; use VO\Database\PdoConnection; use VO\Installer\InstallerSeeder;

/**
 * Unit C (specs N8, N9, N11, admin-shell A1/A2): the notifications settings
 * section (flags + SMTP/bridge fields with write-only secrets), the guarded
 * read-only /admin/notificaciones log with filters/pagination/truncation, the
 * sweep-on-load wiring (an unconfigured channel flips a cap-edge row to failed;
 * a configured channel sends through a settings-built SmtpClient against a
 * local fake SMTP server), and the dashboard card. Real HTTP over a scratch
 * database; runs serially (sockets + built-in server).
 */
final class NotificationsAdminHttpTest extends TestCase
{
    public function testSettingsSectionSavesValidatesAndKeepsSecretsWriteOnly(): void
    {
        $s=NotificationsAdminScratchDatabase::create(); [$h]=$this->harness($s,'settings');
        try {
            $s->setting('smtp_password','OLD_STORED_SECRET'); $s->setting('whatsapp_bridge_token','OLD_BRIDGE_TOKEN'); $this->login($h);
            $page=$h->request('GET','/admin/configuracion'); $this->assertSame(200,$page['status']);
            $this->assertTrue(str_contains($page['body'],'Notificaciones'),'notifications section must render');
            $this->assertTrue(!str_contains($page['body'],'OLD_STORED_SECRET')&&!str_contains($page['body'],'OLD_BRIDGE_TOKEN'),'stored secrets must never echo');
            $csrf=$this->csrf($page['body']);
            $r=$h->request('POST','/admin/configuracion',['csrf'=>$csrf,'notifications_enabled'=>'1','notifications_email_enabled'=>'1','notifications_whatsapp_enabled'=>'1','smtp_host'=>'smtp.example.test','smtp_port'=>'2525','smtp_username'=>'mailer','smtp_password'=>'NEW_SECRET_1','smtp_from_email'=>'no-reply@example.test','smtp_from_name'=>'Demo','whatsapp_bridge_url'=>'https://bridge.example.test/send','whatsapp_bridge_token'=>'NEW_TOKEN_1']);
            $this->assertSame(302,$r['status']);
            $this->assertSame('1',$s->settingValue('notifications_enabled')); $this->assertSame('1',$s->settingValue('notifications_email_enabled')); $this->assertSame('1',$s->settingValue('notifications_whatsapp_enabled'));
            $this->assertSame('smtp.example.test',$s->settingValue('smtp_host')); $this->assertSame('2525',$s->settingValue('smtp_port'));
            $this->assertSame('NEW_SECRET_1',$s->settingValue('smtp_password')); $this->assertSame('NEW_TOKEN_1',$s->settingValue('whatsapp_bridge_token'));
            $blob=(string)$s->pdo->query("SELECT GROUP_CONCAT(metadata_json) FROM audit_log WHERE action='settings.updated'")->fetchColumn();
            $this->assertTrue(str_contains($blob,'smtp_password')&&str_contains($blob,'whatsapp_bridge_token'),'audit lists changed key names');
            $this->assertTrue(!str_contains($blob,'NEW_SECRET_1')&&!str_contains($blob,'NEW_TOKEN_1')&&!str_contains($blob,'OLD_STORED_SECRET'),'audit must never contain secret values');
            $body=$h->request('GET','/admin/configuracion')['body'];
            $this->assertTrue(!str_contains($body,'NEW_SECRET_1')&&!str_contains($body,'NEW_TOKEN_1'),'page must never echo saved secrets');
            // keep-if-empty: posting empty secrets preserves the stored values
            $this->assertSame(302,$h->request('POST','/admin/configuracion',['csrf'=>$csrf,'smtp_host'=>'smtp2.example.test'])['status']);
            $this->assertSame('NEW_SECRET_1',$s->settingValue('smtp_password')); $this->assertSame('NEW_TOKEN_1',$s->settingValue('whatsapp_bridge_token')); $this->assertSame('smtp2.example.test',$s->settingValue('smtp_host'));
            // validation runs BEFORE any save: a failed POST must not clobber stored values
            $bad=$h->request('POST','/admin/configuracion',['csrf'=>$csrf,'smtp_port'=>'99999','smtp_host'=>'smtp3.example.test']); $this->assertSame(422,$bad['status']);
            $this->assertTrue(str_contains($bad['body'],'puerto'),(string)$bad['body']);
            $this->assertSame('smtp2.example.test',$s->settingValue('smtp_host'),'invalid POST must not save');
            $badMail=$h->request('POST','/admin/configuracion',['csrf'=>$csrf,'smtp_from_email'=>'not-an-email']); $this->assertSame(422,$badMail['status']);
            $this->assertTrue(str_contains($badMail['body'],'email'));
            $this->assertSame(419,$h->request('POST','/admin/configuracion',['csrf'=>'deadbeef','smtp_host'=>'x'])['status'],'CSRF must reject the settings POST');
        } finally {$h->stop();$s->drop();}
    }

    public function testLogPageGuardListFiltersTruncationAndSweepFlipsToFailed(): void
    {
        $s=NotificationsAdminScratchDatabase::create(); [$h]=$this->harness($s,'log');
        try {
            $this->login($h);
            $s->removePermission('settings.manage');
            $deny=$h->request('GET','/admin/notificaciones'); $this->assertSame(403,$deny['status']);
            $this->assertSame(1,(int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='authz.denied' AND metadata_json LIKE '%settings.manage%'")->fetchColumn(),'denial must be audited');
            $s->grantPermission('settings.manage');
            // cap-edge pending row: one more failed attempt reaches the cap → failed
            $edge=$s->notification('order.ready','email','edge@example.test','pending',2);
            $sent=$s->notification('delivery.assigned','whatsapp','sent@example.test','sent',1); $s->pdo->exec("UPDATE notification_events SET sent_at=NOW() WHERE id=$sent");
            $s->notification('payment.rejected','email','failed@example.test','failed',3,str_repeat('E',300));
            $retry=$s->notification('order.created','whatsapp','retry@example.test','pending',0);
            $list=$h->request('GET','/admin/notificaciones'); $this->assertSame(200,$list['status']);
            foreach(['Notificaciones','Listo para retiro','edge@example.test','sent@example.test','failed@example.test','retry@example.test','WhatsApp','Email','Enviado','Fallido','Intentos: 3'] as $n)$this->assertTrue(str_contains($list['body'],$n),$n);
            $this->assertTrue(str_contains($list['body'],str_repeat('E',120))&&str_contains($list['body'],'…'),'long last_error renders truncated');
            $this->assertTrue(!str_contains($list['body'],str_repeat('E',121)),'last_error must be truncated');
            // sweep on load consumed both sweepable rows
            $this->assertSame('failed',$s->stateOf($edge)); $this->assertSame(3,(int)$s->attemptsOf($edge)); $this->assertSame('canal no configurado',(string)$s->errorOf($edge));
            $this->assertSame('pending',$s->stateOf($retry)); $this->assertSame(1,(int)$s->attemptsOf($retry));
            $pending=$h->request('GET','/admin/notificaciones?state=pending'); $this->assertTrue(str_contains($pending['body'],'retry@example.test')); $this->assertTrue(!str_contains($pending['body'],'sent@example.test'));
            $onlySent=$h->request('GET','/admin/notificaciones?state=sent'); $this->assertTrue(str_contains($onlySent['body'],'sent@example.test')); $this->assertTrue(!str_contains($onlySent['body'],'retry@example.test'));
            $whatsapp=$h->request('GET','/admin/notificaciones?channel=whatsapp'); $this->assertTrue(str_contains($whatsapp['body'],'retry@example.test')&&str_contains($whatsapp['body'],'sent@example.test')); $this->assertTrue(!str_contains($whatsapp['body'],'failed@example.test'));
            $card=$h->request('GET','/admin/'); $this->assertSame(200,$card['status']);
            $this->assertTrue(str_contains($card['body'],'Notificaciones')&&str_contains($card['body'],'/admin/notificaciones'),'dashboard must link the log');
        } finally {$h->stop();$s->drop();}
    }

    public function testSweepSendsThroughSettingsBuiltSmtpAndPaginates(): void
    {
        $s=NotificationsAdminScratchDatabase::create(); [$h]=$this->harness($s,'sweep');
        try {
            for ($i=1;$i<=51;$i++) $s->notification('order.accepted','whatsapp',sprintf('page%02d@example.test',$i),'pending',3); // cap-excluded: never swept, drives pagination
            [$proc,$port,$log]=$this->fakeSmtp($this->tempDir('smtp'));
            $this->login($h);
            $page=$h->request('GET','/admin/configuracion'); $csrf=$this->csrf($page['body']);
            $this->assertSame(302,$h->request('POST','/admin/configuracion',['csrf'=>$csrf,'notifications_enabled'=>'1','notifications_email_enabled'=>'1','smtp_host'=>'127.0.0.1','smtp_port'=>(string)$port,'smtp_username'=>'mailer','smtp_password'=>'pass','smtp_from_email'=>'no-reply@example.test','smtp_from_name'=>'Demo'])['status']);
            $pending=$s->notification('order.accepted','email','sweep@example.test','pending',0);
            $list=$h->request('GET','/admin/notificaciones'); $this->assertSame(200,$list['status']);
            $deadline=microtime(true)+8; $dialogue='';
            while (microtime(true)<$deadline) { $dialogue=(string)@file_get_contents($log); if (str_contains($dialogue,'QUIT')) break; usleep(200000); }
            foreach(['MAIL FROM:<no-reply@example.test>','RCPT TO:<sweep@example.test>','QUIT'] as $n)$this->assertTrue(str_contains($dialogue,$n),'SMTP dialogue missing '.$n.': '.$dialogue); // replies (250 queued) are not logged; the state assert below proves the outcome
            $this->assertSame('sent',$s->stateOf($pending)); $this->assertNotSame(null,$s->pdo->query("SELECT sent_at FROM notification_events WHERE id=$pending")->fetchColumn());
            $p1=$h->request('GET','/admin/notificaciones'); $this->assertTrue(str_contains($p1['body'],'sweep@example.test')&&str_contains($p1['body'],'page03@example.test'));
            $this->assertTrue(!str_contains($p1['body'],'page02@example.test'),'page one holds 50 rows');
            $p2=$h->request('GET','/admin/notificaciones?page=2'); $this->assertTrue(str_contains($p2['body'],'page02@example.test')&&str_contains($p2['body'],'page01@example.test'),'page two holds the rest');
            proc_terminate($proc);
        } finally {$h->stop();$s->drop();}
    }

    private function harness(NotificationsAdminScratchDatabase $s,string $name): array {$dir=$this->tempDir('notifications_'.$name);$lock=$dir.'/installed.php';$s->install($lock);$h=new \InstallerTestServer(dirname(__DIR__).'/public_html',['VO_INSTALLED_CONFIG_PATH'=>$lock,'VO_HTTP_NO_REDIRECTS'=>'1']);$h->start();return[$h];}
    private function login(\InstallerTestServer $h): void {$csrf=$this->csrf($h->request('GET','/admin/login')['body']);$this->assertSame(302,$h->request('POST','/admin/login',['csrf'=>$csrf,'email'=>'owner@example.test','password'=>'Password123'])['status']);}
    private function csrf(string $html): string {if(!preg_match('/name="csrf" value="([^"]+)"/',$html,$m))throw new \RuntimeException('Missing CSRF');return $m[1];}

    /** Local fake SMTP server in a child PHP process; dialogue logged to $log for assertions. @return array{resource,int,string} */
    private function fakeSmtp(string $dir): array
    {
        $port=0;
        for ($i=0;$i<25;$i++){$p=random_int(20000,45000);$probe=@stream_socket_server('tcp://127.0.0.1:'.$p,$errno,$errstr);if($probe!==false){fclose($probe);$port=$p;break;}}
        if($port===0)throw new \RuntimeException('No usable local port for fake SMTP.');
        $log=$dir.'/smtp.log'; $script=$dir.'/fake_smtp.php';
        file_put_contents($script,'<?php
$server=stream_socket_server("tcp://127.0.0.1:".$argv[1],$errno,$errstr);
if($server===false){exit(1);}
file_put_contents($argv[2],"ready\n");
$deadline=time()+30;$handled=0;
while($handled<3&&time()+5<$deadline){
    $client=@stream_socket_accept($server,5);
    if($client===false)continue;
    fwrite($client,"220 stub ESMTP\r\n");
    $stage=0;$inData=false;
    while(($line=fgets($client,1024))!==false){
        file_put_contents($argv[2],trim($line)."\n",FILE_APPEND);
        if($inData){if(rtrim($line,"\r\n")==="."){fwrite($client,"250 queued\r\n");$inData=false;}continue;}
        $cmd=strtoupper(substr(ltrim($line),0,4));
        if($cmd==="EHLO")fwrite($client,"250-stub\r\n250-8BITMIME\r\n250 AUTH LOGIN\r\n");
        elseif($cmd==="AUTH"){fwrite($client,"334 VXNlcm5hbWU6\r\n");$stage=1;}
        elseif($stage===1){fwrite($client,"334 UGFzc3dvcmQ6\r\n");$stage=2;}
        elseif($stage===2){fwrite($client,"235 2.0.0 ok\r\n");$stage=0;}
        elseif($cmd==="MAIL"||$cmd==="RCPT")fwrite($client,"250 ok\r\n");
        elseif($cmd==="DATA"){fwrite($client,"354 go\r\n");$inData=true;}
        elseif($cmd==="QUIT"){fwrite($client,"221 bye\r\n");break;}
        else fwrite($client,"250 ok\r\n");
    }
    fclose($client);$handled++;file_put_contents($argv[2],"done\n",FILE_APPEND);
}');
        $proc=proc_open([PHP_BINARY,$script,(string)$port,$log],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,$dir);
        if(!is_resource($proc))throw new \RuntimeException('Could not start fake SMTP server.');
        for($i=0;$i<50&&!is_file($log);$i++)usleep(100000);
        return[$proc,$port,$log];
    }
}

final class NotificationsAdminScratchDatabase
{
    private function __construct(public string $name,public PDO $pdo,private PDO $server){}
    public static function create(): self {try{$server=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}catch(Throwable $e){throw new \RuntimeException('MariaDB is REQUIRED for NotificationsAdminHttpTest.',0,$e);}$name='vo_notif11_test_'.bin2hex(random_bytes(5));$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$pdo=new PDO("mysql:host=127.0.0.1;port=3306;dbname=$name;charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);return new self($name,$pdo,$server);}
    public function install(string $lock): void {$db=new PdoConnection("mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4",'root','');(new MigrationRunner($db,dirname(__DIR__).'/api/database/migrations'))->run();(new InstallerSeeder($db))->seed(['business_name'=>'Demo','business_slug'=>'demo','branch_name'=>'Centro','timezone'=>'UTC','admin_name'=>'Owner','admin_email'=>'owner@example.test','admin_password'=>'Password123']);if(!is_dir(dirname($lock)))mkdir(dirname($lock),0777,true);file_put_contents($lock,'<?php return '.var_export(['database'=>['dsn'=>"mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4",'user'=>'root','password'=>'']],true).';');}
    /** @return int row id */
    public function notification(string $event,string $channel,string $recipient,string $state='pending',int $attempts=0,?string $lastError=null): int {$q=$this->pdo->prepare('INSERT INTO notification_events (business_id,event,channel,recipient,context_json,state,attempts,last_error) VALUES (1,?,?,?,?,?,?,?)');$q->execute([$event,$channel,$recipient,json_encode(['order_id'=>1,'order_number'=>'B-000001']),$state,$attempts,$lastError]);return (int)$this->pdo->lastInsertId();}
    public function setting(string $key,string $value): void {$this->pdo->prepare('INSERT INTO business_settings (business_id,setting_key,setting_value) VALUES (1,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,$value]);} public function settingValue(string $key): string {$s=$this->pdo->prepare('SELECT setting_value FROM business_settings WHERE business_id=1 AND setting_key=?');$s->execute([$key]);return(string)$s->fetchColumn();}
    public function removePermission(string $key): void {$this->pdo->prepare('DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_key=?')->execute([$key]);} public function grantPermission(string $key): void {$this->pdo->prepare('INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key=? LIMIT 1')->execute([$key]);}
    public function stateOf(int $id): string {return(string)$this->pdo->query("SELECT state FROM notification_events WHERE id=$id")->fetchColumn();}
    public function attemptsOf(int $id): int {return(int)$this->pdo->query("SELECT attempts FROM notification_events WHERE id=$id")->fetchColumn();}
    public function errorOf(int $id): ?string {return $this->pdo->query("SELECT last_error FROM notification_events WHERE id=$id")->fetchColumn()?:null;}
    public function drop(): void {$this->server->exec("DROP DATABASE IF EXISTS `$this->name`");}
}
