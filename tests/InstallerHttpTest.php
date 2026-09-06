<?php declare(strict_types=1);
namespace Tests;
use PDO; use Throwable;

final class InstallerHttpTest extends TestCase
{
    public function testWizardOrderAndRequirementFailureBlocksLaterSteps(): void
    {
        $h=$this->harness(['VO_INSTALLER_FORCE_REQUIREMENT_FAIL'=>'1']);
        try { $r=$h->request('GET','/install/'); $this->assertSame(200,$r['status']); $this->assertTrue(str_contains($r['body'],'verificamos'),'Requirements step was not shown first.');
            $csrf=$this->csrf($r['body']); $r=$h->request('POST','/install/?step=requirements',['csrf'=>$csrf]); $this->assertSame(400,$r['status']);
            $r=$h->request('GET','/install/?step=details'); $this->assertSame(400,$r['status']); $this->assertTrue(!str_contains($r['body'],'Contraseña'),'Details step leaked before requirements passed.');
        } finally { $h->stop(); }
    }

    public function testBadDbIsSanitizedAndMissingCsrfIsRejected(): void
    {
        $h=$this->harness(); $secret='super-secret-db-pass';
        try { $r=$h->request('GET','/install/'); $csrf=$this->csrf($r['body']); $h->request('POST','/install/?step=requirements',['csrf'=>$csrf]);
            $r=$h->request('POST','/install/?step=database',['csrf'=>$csrf,'host'=>'127.0.0.1','port'=>'3306','name'=>'missing_db','user'=>'bad','password'=>$secret]);
            $this->assertSame(400,$r['status']); $this->assertTrue(str_contains($r['body'],'No se pudo conectar')); $this->assertTrue(!str_contains($r['body'],$secret));
            $r=$h->request('POST','/install/?step=database',['name'=>'x']); $this->assertSame(419,$r['status']);
        } finally { $h->stop(); }
    }

    public function testSuccessfulInstallWritesLockLastAndDeferredFeaturesStayAbsent(): void
    {
        $scratch=HttpScratchDatabase::create(); if ($scratch===null) return; $dir=$this->tempDir('http_ok'); $lock=$dir.'/installed.php'; $h=$this->harness(['VO_INSTALLED_CONFIG_PATH'=>$lock,'VO_STORAGE_PATH'=>$dir.'/storage']);
        try { $this->complete($h,$scratch); $this->assertTrue(is_file($lock),'Lock must be written after install.');
            $this->assertSame(1,(int)$scratch->pdo->query('SELECT COUNT(*) FROM businesses')->fetchColumn()); $this->assertSame(1,(int)$scratch->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='installer.completed'")->fetchColumn());
            $r=$h->request('GET','/install/'); $this->assertSame(410,$r['status']); $this->assertTrue(!str_contains($r['body'],'<form'));
            $r=$h->request('GET','/login'); $this->assertSame(404,$r['status']);
        } finally { $h->stop(); $scratch->drop(); }
    }

    public function testPartialFailureLeavesNoLockAndRerunIsSafe(): void
    {
        $scratch=HttpScratchDatabase::create(); if ($scratch===null) return; $dir=$this->tempDir('http_retry'); $lock=$dir.'/installed.php';
        $h=$this->harness(['VO_INSTALLED_CONFIG_PATH'=>$lock,'VO_STORAGE_PATH'=>$dir.'/storage','VO_INSTALLER_FAIL_AFTER_MIGRATIONS'=>'1']);
        try { $this->complete($h,$scratch,500); $this->assertTrue(!is_file($lock)); } finally { $h->stop(); }
        $h=$this->harness(['VO_INSTALLED_CONFIG_PATH'=>$lock,'VO_STORAGE_PATH'=>$dir.'/storage']);
        try { $this->complete($h,$scratch); $this->assertTrue(is_file($lock)); $this->assertSame(1,(int)$scratch->pdo->query('SELECT COUNT(*) FROM businesses')->fetchColumn()); } finally { $h->stop(); $scratch->drop(); }
    }

    public function testInstalledLockShortCircuitsBeforeDbAccess(): void
    {
        $dir=$this->tempDir('http_lock'); $lock=$dir.'/installed.php'; file_put_contents($lock,'<?php return ["installed"=>true];'); $h=$this->harness(['VO_INSTALLED_CONFIG_PATH'=>$lock,'VO_STORAGE_PATH'=>$dir.'/storage','VO_INSTALLER_FORCE_REQUIREMENT_FAIL'=>'1']);
        try { $r=$h->request('GET','/install/?step=database'); $this->assertSame(410,$r['status']); $this->assertTrue(str_contains($r['body'],'Instalador bloqueado')); $this->assertTrue(!str_contains($r['body'],'Base de datos')); } finally { $h->stop(); }
    }

    private function harness(array $env=[]): \InstallerTestServer { $h=new \InstallerTestServer(dirname(__DIR__).'/public_html',$env); $h->start(); return $h; }
    private function csrf(string $html): string { if (!preg_match('/name="csrf" value="([^"]+)"/',$html,$m)) throw new \RuntimeException('Missing CSRF token.'); return $m[1]; }
    private function complete(\InstallerTestServer $h, HttpScratchDatabase $db, int $runStatus=200): void
    { $r=$h->request('GET','/install/'); $csrf=$this->csrf($r['body']); $h->request('POST','/install/?step=requirements',['csrf'=>$csrf]); $r=$h->request('GET','/install/?step=database'); $csrf=$this->csrf($r['body']); $r=$h->request('POST','/install/?step=database',['csrf'=>$csrf,'host'=>'127.0.0.1','port'=>'3306','name'=>$db->name,'user'=>'root','password'=>'']); $this->assertSame(200,$r['status']); $this->assertTrue(str_contains($r['body'],'Datos iniciales')); $r=$h->request('GET','/install/?step=details'); $csrf=$this->csrf($r['body']); $r=$h->request('POST','/install/?step=details',['csrf'=>$csrf,'business_name'=>'Demo Store','branch_name'=>'Centro','admin_name'=>'Dueño','admin_email'=>'owner@example.test','admin_password'=>'Password123','admin_password_confirm'=>'Password123']); $this->assertSame(200,$r['status']); $this->assertTrue(str_contains($r['body'],'Revisar e instalar')); $r=$h->request('GET','/install/?step=review'); $csrf=$this->csrf($r['body']); $r=$h->request('POST','/install/?step=run',['csrf'=>$csrf]); $this->assertSame($runStatus,$r['status']); }
}

final class HttpScratchDatabase
{
    private function __construct(public string $name, public PDO $pdo, private PDO $server) {}
    public static function create(): ?self { try { $s=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); } catch (Throwable $e) { echo 'SKIP MariaDB unavailable: '.$e->getMessage().PHP_EOL; return null; } $n='vo_installer_test_'.bin2hex(random_bytes(5)); $s->exec("CREATE DATABASE `$n` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $p=new PDO("mysql:host=127.0.0.1;port=3306;dbname=$n;charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); return new self($n,$p,$s); }
    public function drop(): void { $this->server->exec("DROP DATABASE IF EXISTS `$this->name`"); }
}
