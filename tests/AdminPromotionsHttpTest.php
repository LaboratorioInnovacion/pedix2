<?php declare(strict_types=1);
namespace Tests;

use PDO;
use Throwable;
use VO\Database\MigrationRunner;
use VO\Database\PdoConnection;
use VO\Installer\InstallerSeeder;

final class AdminPromotionsHttpTest extends TestCase
{
    public function testPermissionDenyWritesAudit(): void
    {
        $s = PromotionsHttpScratchDatabase::create(); if ($s === null) return;
        [$h] = $this->installedHarness($s, 'promotions_deny');
        try {
            $s->pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_key='products.manage'");
            $this->login($h);
            $r = $h->request('GET', '/admin/promociones');
            $this->assertSame(403, $r['status']);
            $this->assertTrue(str_contains($r['body'], 'No tenés permiso'));
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='authz.denied' AND request_id IS NOT NULL")->fetchColumn());
        } finally { $h->stop(); $s->drop(); }
    }

    public function testPromotionCreateCsrfValidationAndReadOnlyUsage(): void
    {
        $s = PromotionsHttpScratchDatabase::create(); if ($s === null) return;
        [$h] = $this->installedHarness($s, 'promotion_create');
        try {
            $this->login($h); $item = $this->item($s);
            $r = $h->request('GET', '/admin/promociones'); $csrf = $this->csrf($r['body']);
            $bad = $h->request('POST', '/admin/promociones', ['csrf'=>'bad','name'=>'Promo','type'=>'product_pct']);
            $this->assertSame(419, $bad['status']);
            $this->assertSame(0, (int)$s->pdo->query('SELECT COUNT(*) FROM promotions')->fetchColumn());
            $err = $h->request('POST', '/admin/promociones', ['csrf'=>$csrf,'name'=>'','type'=>'bad','discount_basis_points'=>'abc']);
            $this->assertSame(200, $err['status']);
            $this->assertTrue(str_contains($err['body'], 'El nombre es obligatorio.') && str_contains($err['body'], 'El tipo de promoción no es válido.'));
            $ok = $h->request('POST', '/admin/promociones', ['csrf'=>$csrf,'name'=>'Promo verano','type'=>'product_pct','priority'=>'5','is_stackable'=>'1','starts_at'=>'2026-01-01 00:00:00','ends_at'=>'2026-12-31 23:59:59','usage_limit'=>'10','times_used'=>'99','scope'=>'item','scope_id'=>(string)$item,'discount_basis_points'=>'1500']);
            $this->assertSame(302, $ok['status']);
            $p = $s->pdo->query("SELECT * FROM promotions WHERE name='Promo verano'")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame(5, (int)$p['priority']); $this->assertSame(1, (int)$p['is_stackable']); $this->assertSame(0, (int)$p['times_used']);
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM promotion_rules WHERE promotion_id={$p['id']} AND scope='item' AND scope_id=$item AND discount_basis_points=1500")->fetchColumn());
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='pricing.promotion_created' AND request_id IS NOT NULL")->fetchColumn());
            $edit = $h->request('GET', '/admin/promociones/' . (int)$p['id']); $csrf = $this->csrf($edit['body']);
            $up = $h->request('POST', '/admin/promociones/' . (int)$p['id'], ['csrf'=>$csrf,'name'=>'Promo verano plus','type'=>'product_pct','priority'=>'2','times_used'=>'9','scope'=>'item','scope_id'=>(string)$item,'discount_basis_points'=>'2000']);
            $this->assertSame(302, $up['status']);
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM promotions WHERE id={$p['id']} AND name='Promo verano plus' AND priority=2 AND times_used=0")->fetchColumn());
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='pricing.promotion_updated' AND request_id IS NOT NULL")->fetchColumn());
            $list = $h->request('GET', '/admin/promociones');
            $this->assertTrue(str_contains($list['body'], 'Usos actuales') && !str_contains($list['body'], 'name="times_used"'));
            $this->assertTrue(!str_contains($list['body'], '2x1') && !str_contains($list['body'], '3x2') && !str_contains($list['body'], 'stock'));
        } finally { $h->stop(); $s->drop(); }
    }

    public function testCouponCreateStoresLimitMinimumValidityAndUsageReadonly(): void
    {
        $s = PromotionsHttpScratchDatabase::create(); if ($s === null) return;
        [$h] = $this->installedHarness($s, 'coupon_create');
        try {
            $this->login($h); $r = $h->request('GET', '/admin/cupones'); $csrf = $this->csrf($r['body']);
            $r = $h->request('POST', '/admin/cupones', ['csrf'=>$csrf,'code'=>'verano15','discount_basis_points'=>'1500','min_amount_cents'=>'2500','starts_at'=>'2026-01-01 00:00:00','ends_at'=>'2026-02-01 00:00:00','usage_limit'=>'25','times_used'=>'12']);
            $this->assertSame(302, $r['status']);
            $c = $s->pdo->query("SELECT * FROM coupons WHERE code='VERANO15'")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame(2500, (int)$c['min_amount_cents']); $this->assertSame(25, (int)$c['usage_limit']); $this->assertSame(0, (int)$c['times_used']);
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='pricing.coupon_created' AND request_id IS NOT NULL")->fetchColumn());
            $edit = $h->request('GET', '/admin/cupones/' . (int)$c['id']); $csrf = $this->csrf($edit['body']);
            $r = $h->request('POST', '/admin/cupones/' . (int)$c['id'], ['csrf'=>$csrf,'code'=>'invierno20','discount_basis_points'=>'2000','min_amount_cents'=>'3000','usage_limit'=>'30','times_used'=>'11']);
            $this->assertSame(302, $r['status']);
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM coupons WHERE id={$c['id']} AND code='INVIERNO20' AND min_amount_cents=3000 AND usage_limit=30 AND times_used=0")->fetchColumn());
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='pricing.coupon_updated' AND request_id IS NOT NULL")->fetchColumn());
            $this->assertTrue(str_contains($h->request('GET', '/admin/cupones/' . (int)$c['id'])['body'], 'Usos actuales'));
            $r = $h->request('POST', '/admin/cupones/' . (int)$c['id'] . '/archivar', ['csrf'=>$csrf]);
            $this->assertSame(302, $r['status']);
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM coupons WHERE id={$c['id']} AND archived_at IS NOT NULL")->fetchColumn());
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='pricing.coupon_archived' AND request_id IS NOT NULL")->fetchColumn());
        } finally { $h->stop(); $s->drop(); }
    }

    public function testArchivePromotionKeepsRowAndAuditsRequestId(): void
    {
        $s = PromotionsHttpScratchDatabase::create(); if ($s === null) return;
        [$h] = $this->installedHarness($s, 'promotion_archive');
        try {
            $this->login($h); $item = $this->item($s); $csrf = $this->csrf($h->request('GET', '/admin/promociones')['body']);
            $h->request('POST', '/admin/promociones', ['csrf'=>$csrf,'name'=>'Promo archivar','type'=>'product_fixed','scope'=>'item','scope_id'=>(string)$item,'fixed_cents'=>'300']);
            $id = (int)$s->pdo->query("SELECT id FROM promotions WHERE name='Promo archivar'")->fetchColumn();
            $r = $h->request('POST', '/admin/promociones/' . $id . '/archivar', ['csrf'=>$csrf]);
            $this->assertSame(302, $r['status']);
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM promotions WHERE id=$id AND archived_at IS NOT NULL")->fetchColumn());
            $a = $s->pdo->query("SELECT entity_type,entity_id,request_id FROM audit_log WHERE action='pricing.promotion_archived' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('promotion', $a['entity_type']); $this->assertSame($id, (int)$a['entity_id']); $this->assertNotSame(null, $a['request_id']);
        } finally { $h->stop(); $s->drop(); }
    }

    private function installedHarness(PromotionsHttpScratchDatabase $s, string $name): array { $dir = $this->tempDir($name); $lock = $dir . '/installed.php'; $s->install($lock); $h = new \InstallerTestServer(dirname(__DIR__) . '/public_html', ['VO_INSTALLED_CONFIG_PATH'=>$lock,'VO_STORAGE_PATH'=>$dir . '/storage','VO_HTTP_NO_REDIRECTS'=>'1']); $h->start(); return [$h,$lock]; }
    private function login(\InstallerTestServer $h): void { $r = $h->request('GET', '/admin/login'); $csrf = $this->csrf($r['body']); $r = $h->request('POST', '/admin/login', ['csrf'=>$csrf,'email'=>'owner@example.test','password'=>'Password123']); $this->assertSame(302, $r['status']); }
    private function csrf(string $html): string { if (!preg_match('/name="csrf" value="([^"]+)"/', $html, $m)) throw new \RuntimeException('Missing CSRF token. Body: ' . substr($html, 0, 160)); return $m[1]; }
    private function item(PromotionsHttpScratchDatabase $s): int { $s->pdo->exec("INSERT INTO categories (name,slug,is_active) VALUES ('Pizzas','pizzas',1)"); $s->pdo->exec("INSERT INTO catalog_items (category_id,type,name,slug,base_price_cents,is_active) VALUES ((SELECT id FROM categories LIMIT 1),'product','Pizza','pizza',1200,1)"); return (int)$s->pdo->lastInsertId(); }
}

final class PromotionsHttpScratchDatabase
{
    private function __construct(public string $name, public PDO $pdo, private PDO $server) {}
    public static function create(): ?self { try { $s = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); } catch (Throwable $e) { echo 'SKIP MariaDB unavailable: ' . $e->getMessage() . PHP_EOL; return null; } $n = 'vo_pricing_test_' . bin2hex(random_bytes(5)); $s->exec("CREATE DATABASE `$n` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $p = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$n;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); return new self($n, $p, $s); }
    public function install(string $lock): void { $db = new PdoConnection("mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4", 'root', ''); (new MigrationRunner($db, dirname(__DIR__) . '/api/database/migrations'))->run(); (new InstallerSeeder($db))->seed(['business_name'=>'Demo Store','business_slug'=>'demo-store','branch_name'=>'Centro','timezone'=>'America/Argentina/Buenos_Aires','admin_name'=>'Dueño','admin_email'=>'owner@example.test','admin_password'=>'Password123']); if (!is_dir(dirname($lock))) mkdir(dirname($lock), 0777, true); file_put_contents($lock, '<?php return ' . var_export(['database'=>['dsn'=>"mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4",'user'=>'root','password'=>'']], true) . ';'); }
    public function drop(): void { $this->server->exec("DROP DATABASE IF EXISTS `$this->name`"); }
}
