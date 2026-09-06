<?php declare(strict_types=1);
namespace Tests;

use PDO;
use Throwable;
use VO\Database\MigrationRunner;
use VO\Database\PdoConnection;
use VO\Installer\InstallerSeeder;

final class AdminCatalogHttpTest extends TestCase
{
    public function testPermissionDenyWritesAudit(): void
    {
        $s = CatalogHttpScratchDatabase::create(); if ($s === null) return;
        [$h] = $this->installedHarness($s, 'catalog_deny');
        try {
            $s->pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_key='products.manage'");
            $this->login($h);
            $r = $h->request('GET', '/admin/catalogo');
            $this->assertSame(403, $r['status']);
            $this->assertTrue(str_contains($r['body'], 'No tenés permiso'));
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='authz.denied'")->fetchColumn());
        } finally { $h->stop(); $s->drop(); }
    }

    public function testCategoryCrudCsrfAndValidation(): void
    {
        $s = CatalogHttpScratchDatabase::create(); if ($s === null) return;
        [$h] = $this->installedHarness($s, 'catalog_category');
        try {
            $this->login($h);
            $r = $h->request('GET', '/admin/categorias'); $csrf = $this->csrf($r['body']);
            $bad = $h->request('POST', '/admin/categorias', ['csrf'=>'bad','name'=>'Bebidas','slug'=>'bebidas','is_active'=>'1']);
            $this->assertSame(419, $bad['status']);
            $this->assertSame(0, (int)$s->pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn());
            $err = $h->request('POST', '/admin/categorias', ['csrf'=>$csrf,'name'=>'','slug'=>'bebidas']);
            $this->assertSame(200, $err['status']);
            $this->assertTrue(str_contains($err['body'], 'El nombre es obligatorio.'));
            $ok = $h->request('POST', '/admin/categorias', ['csrf'=>$csrf,'name'=>'Bebidas','slug'=>'bebidas','is_active'=>'1']);
            $this->assertSame(302, $ok['status']);
            $cat = (int)$s->pdo->query("SELECT id FROM categories WHERE slug='bebidas' AND archived_at IS NULL")->fetchColumn();
            $this->assertTrue($cat > 0);
            $r = $h->request('POST', '/admin/categorias', ['csrf'=>$csrf,'id'=>(string)$cat,'name'=>'Bebidas frías','slug'=>'bebidas-frias','is_active'=>'1']);
            $this->assertSame(302, $r['status']);
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM categories WHERE id=$cat AND slug='bebidas-frias'")->fetchColumn());
            $r = $h->request('POST', '/admin/categorias', ['csrf'=>$csrf,'id'=>(string)$cat,'action'=>'archive']);
            $this->assertSame(302, $r['status']);
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM categories WHERE id=$cat AND archived_at IS NOT NULL")->fetchColumn());
        } finally { $h->stop(); $s->drop(); }
    }

    public function testItemCreateVariantsModifierPriceAuditArchiveAndWrongMethod(): void
    {
        $s = CatalogHttpScratchDatabase::create(); if ($s === null) return;
        [$h] = $this->installedHarness($s, 'catalog_item');
        try {
            $this->login($h);
            $s->pdo->exec("INSERT INTO modifier_groups (name,is_required,selection,min_select,max_select,is_active) VALUES ('Salsas',0,'single',0,1,1)");
            $group = (int)$s->pdo->lastInsertId();
            $r = $h->request('GET', '/admin/productos'); $csrf = $this->csrf($r['body']);
            $r = $h->request('POST', '/admin/productos', ['csrf'=>$csrf,'name'=>'Pizza','slug'=>'pizza','type'=>'product','base_price_cents'=>'1200','requires_variant'=>'1','allows_pickup'=>'1','allows_delivery'=>'1','is_active'=>'1','variant_name'=>['Grande'],'variant_price_cents'=>['1500'],'modifier_group_id'=>[$group]]);
            $this->assertSame(302, $r['status']);
            $item = (int)$s->pdo->query("SELECT id FROM catalog_items WHERE slug='pizza'")->fetchColumn();
            $this->assertTrue($item > 0);
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM item_variants WHERE item_id=$item AND name='Grande'")->fetchColumn());
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM item_modifier_group WHERE item_id=$item AND group_id=$group")->fetchColumn());

            $edit = $h->request('GET', '/admin/producto/' . $item); $csrf = $this->csrf($edit['body']);
            $r = $h->request('POST', '/admin/producto/' . $item, ['csrf'=>$csrf,'name'=>'Pizza','slug'=>'pizza','type'=>'product','base_price_cents'=>'1800','allows_pickup'=>'1','allows_delivery'=>'1','is_active'=>'1']);
            $this->assertSame(302, $r['status']);
            $row = $s->pdo->query("SELECT metadata_json FROM audit_log WHERE action='catalog.price_changed' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $this->assertTrue(str_contains((string)$row, '"old_cents":1200') && str_contains((string)$row, '"new_cents":1800'));
            $this->assertSame(404, $h->request('GET', '/admin/producto/' . $item . '/archivar')['status']);
            $r = $h->request('POST', '/admin/producto/' . $item . '/archivar', ['csrf'=>$csrf]);
            $this->assertSame(302, $r['status']);
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM catalog_items WHERE id=$item AND archived_at IS NOT NULL")->fetchColumn());
        } finally { $h->stop(); $s->drop(); }
    }

    public function testBranchConfigRequiresBranchScopeAndSavesOverride(): void
    {
        $s = CatalogHttpScratchDatabase::create(); if ($s === null) return;
        [$h] = $this->installedHarness($s, 'catalog_branch');
        try {
            $this->login($h);
            $s->pdo->exec("INSERT INTO categories (name,slug,is_active) VALUES ('Cafetería','cafeteria',1)");
            $s->pdo->exec("INSERT INTO catalog_items (type,name,slug,base_price_cents,is_active) VALUES ('product','Café','cafe',700,1)");
            $item = (int)$s->pdo->lastInsertId();
            $business = (int)$s->pdo->query('SELECT id FROM businesses LIMIT 1')->fetchColumn();
            $s->pdo->exec("INSERT INTO branches (business_id,name) VALUES ($business,'Norte')");
            $other = (int)$s->pdo->lastInsertId();
            $this->assertSame(403, $h->request('GET', '/admin/sucursales/' . $other . '/catalogo')['status']);
            $branch = (int)$s->pdo->query('SELECT branch_id FROM user_branches LIMIT 1')->fetchColumn();
            $r = $h->request('GET', '/admin/sucursales/' . $branch . '/catalogo'); $csrf = $this->csrf($r['body']);
            $r = $h->request('POST', '/admin/sucursales/' . $branch . '/catalogo', ['csrf'=>$csrf,'branch_available'=>[$item=>'1'],'branch_price'=>[$item=>'650'],'stock_mode'=>[$item=>'unlimited']]);
            $this->assertSame(302, $r['status']);
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM branch_items WHERE branch_id=$branch AND item_id=$item AND is_available=1 AND price_override_cents=650 AND stock_mode='unlimited'")->fetchColumn());
        } finally { $h->stop(); $s->drop(); }
    }

    public function testAuditRowsCarryRequestIdForPriceAvailabilityAndArchive(): void
    {
        $s = CatalogHttpScratchDatabase::create(); if ($s === null) return;
        [$h] = $this->installedHarness($s, 'catalog_audit');
        try {
            $this->login($h);
            $s->pdo->exec("INSERT INTO categories (name,slug,is_active) VALUES ('Comidas','comidas',1)");
            $s->pdo->exec("INSERT INTO catalog_items (category_id,type,name,slug,base_price_cents,is_active) VALUES ((SELECT id FROM categories LIMIT 1),'product','Pizza','pizza-audit',1200,1)");
            $item = (int)$s->pdo->lastInsertId();

            $r = $h->request('GET', '/admin/producto/' . $item); $csrf = $this->csrf($r['body']);
            $r = $h->request('POST', '/admin/producto/' . $item, ['csrf'=>$csrf,'name'=>'Pizza','slug'=>'pizza-audit','type'=>'product','base_price_cents'=>'1500','is_active'=>'1']);
            $this->assertSame(302, $r['status']);
            $price = $s->pdo->query("SELECT metadata_json,request_id FROM audit_log WHERE action='catalog.price_changed' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $this->assertNotSame(null, $price['request_id']);
            $meta = json_decode((string)$price['metadata_json'], true);
            $this->assertSame(1200, $meta['old_cents']); $this->assertSame(1500, $meta['new_cents']);
            $this->assertSame('catalog_item', $meta['target_type']); $this->assertSame($item, $meta['target_id']);

            $r = $h->request('POST', '/admin/producto/' . $item, ['csrf'=>$csrf,'name'=>'Pizza','slug'=>'pizza-audit','type'=>'product','base_price_cents'=>'1500','is_active'=>'0']);
            $this->assertSame(302, $r['status']);
            $avail = $s->pdo->query("SELECT metadata_json,request_id FROM audit_log WHERE action='catalog.availability_changed' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $this->assertNotSame(null, $avail['request_id']);
            $meta = json_decode((string)$avail['metadata_json'], true);
            $this->assertSame(1, $meta['old_available']); $this->assertSame(0, $meta['new_available']);
            $this->assertSame('catalog_item', $meta['target_type']); $this->assertSame($item, $meta['target_id']);

            $branch = (int)$s->pdo->query('SELECT branch_id FROM user_branches LIMIT 1')->fetchColumn();
            $r = $h->request('GET', '/admin/sucursales/' . $branch . '/catalogo'); $csrf = $this->csrf($r['body']);
            $r = $h->request('POST', '/admin/sucursales/' . $branch . '/catalogo', ['csrf'=>$csrf,'branch_available'=>[$item=>'1']]);
            $this->assertSame(302, $r['status']);
            $r = $h->request('POST', '/admin/sucursales/' . $branch . '/catalogo', ['csrf'=>$csrf]);
            $this->assertSame(302, $r['status']);
            $avail = $s->pdo->query("SELECT metadata_json,request_id FROM audit_log WHERE action='catalog.availability_changed' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $this->assertNotSame(null, $avail['request_id']);
            $meta = json_decode((string)$avail['metadata_json'], true);
            $this->assertSame(1, $meta['old_available']); $this->assertSame(0, $meta['new_available']);
            $this->assertSame($branch, $meta['branch_id']); $this->assertSame($item, $meta['target_id']);

            $r = $h->request('POST', '/admin/producto/' . $item . '/archivar', ['csrf'=>$csrf]);
            $this->assertSame(302, $r['status']);
            $arch = $s->pdo->query("SELECT entity_type,entity_id,request_id FROM audit_log WHERE action='catalog.archived' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $this->assertNotSame(null, $arch['request_id']);
            $this->assertSame('catalog_item', $arch['entity_type']); $this->assertSame($item, (int)$arch['entity_id']);
        } finally { $h->stop(); $s->drop(); }
    }

    public function testItemImageMetadataPersistsReordersAndSurvivesArchive(): void
    {
        $s = CatalogHttpScratchDatabase::create(); if ($s === null) return;
        [$h] = $this->installedHarness($s, 'catalog_images');
        try {
            $this->login($h);
            $r = $h->request('GET', '/admin/productos'); $csrf = $this->csrf($r['body']);
            $r = $h->request('POST', '/admin/productos', ['csrf'=>$csrf,'name'=>'Pizza','slug'=>'pizza-img','type'=>'product','base_price_cents'=>'900','is_active'=>'1','image_filename'=>['a.jpg','b.jpg'],'image_sort'=>['2','1'],'image_alt'=>['Frente','Detalle']]);
            $this->assertSame(302, $r['status']);
            $item = (int)$s->pdo->query("SELECT id FROM catalog_items WHERE slug='pizza-img'")->fetchColumn();
            $this->assertTrue($item > 0);
            $rows = $s->pdo->query("SELECT filename,alt_text,sort_order,is_active FROM item_images WHERE item_id=$item ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame(2, count($rows));
            $this->assertSame('b.jpg', $rows[0]['filename']); $this->assertSame('Detalle', $rows[0]['alt_text']); $this->assertSame(1, (int)$rows[0]['sort_order']);
            $this->assertSame('a.jpg', $rows[1]['filename']); $this->assertSame('Frente', $rows[1]['alt_text']); $this->assertSame(2, (int)$rows[1]['sort_order']);

            $aId = (int)$s->pdo->query("SELECT id FROM item_images WHERE item_id=$item AND filename='a.jpg'")->fetchColumn();
            $edit = $h->request('GET', '/admin/producto/' . $item); $csrf = $this->csrf($edit['body']);
            $this->assertTrue(str_contains($edit['body'], 'value="a.jpg"'));
            $r = $h->request('POST', '/admin/producto/' . $item, ['csrf'=>$csrf,'name'=>'Pizza','slug'=>'pizza-img','type'=>'product','base_price_cents'=>'900','is_active'=>'1','image_id'=>[(string)$aId],'image_filename'=>['a.jpg'],'image_sort'=>['5'],'image_alt'=>['Frente']]);
            $this->assertSame(302, $r['status']);
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM item_images WHERE item_id=$item AND is_active=1")->fetchColumn());
            $this->assertSame(5, (int)$s->pdo->query("SELECT sort_order FROM item_images WHERE item_id=$item AND is_active=1")->fetchColumn());
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM item_images WHERE item_id=$item AND filename='b.jpg' AND is_active=0")->fetchColumn());

            $r = $h->request('POST', '/admin/producto/' . $item . '/archivar', ['csrf'=>$csrf]);
            $this->assertSame(302, $r['status']);
            $this->assertSame(2, (int)$s->pdo->query("SELECT COUNT(*) FROM item_images WHERE item_id=$item")->fetchColumn());
        } finally { $h->stop(); $s->drop(); }
    }

    private function installedHarness(CatalogHttpScratchDatabase $s, string $name): array { $dir = $this->tempDir($name); $lock = $dir . '/installed.php'; $s->install($lock); $h = new \InstallerTestServer(dirname(__DIR__) . '/public_html', ['VO_INSTALLED_CONFIG_PATH'=>$lock,'VO_STORAGE_PATH'=>$dir . '/storage','VO_HTTP_NO_REDIRECTS'=>'1']); $h->start(); return [$h,$lock]; }
    private function login(\InstallerTestServer $h): void { $r = $h->request('GET', '/admin/login'); $csrf = $this->csrf($r['body']); $r = $h->request('POST', '/admin/login', ['csrf'=>$csrf,'email'=>'owner@example.test','password'=>'Password123']); $this->assertSame(302, $r['status']); }
    private function csrf(string $html): string { if (!preg_match('/name="csrf" value="([^"]+)"/', $html, $m)) throw new \RuntimeException('Missing CSRF token. Body: ' . substr($html, 0, 160)); return $m[1]; }
}

final class CatalogHttpScratchDatabase
{
    private function __construct(public string $name, public PDO $pdo, private PDO $server) {}
    public static function create(): ?self { try { $s = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); } catch (Throwable $e) { echo 'SKIP MariaDB unavailable: ' . $e->getMessage() . PHP_EOL; return null; } $n = 'vo_cat_test_' . bin2hex(random_bytes(5)); $s->exec("CREATE DATABASE `$n` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $p = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$n;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); return new self($n, $p, $s); }
    public function install(string $lock): void { $db = new PdoConnection("mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4", 'root', ''); (new MigrationRunner($db, dirname(__DIR__) . '/api/database/migrations'))->run(); (new InstallerSeeder($db))->seed(['business_name'=>'Demo Store','business_slug'=>'demo-store','branch_name'=>'Centro','timezone'=>'America/Argentina/Buenos_Aires','admin_name'=>'Dueño','admin_email'=>'owner@example.test','admin_password'=>'Password123']); if (!is_dir(dirname($lock))) mkdir(dirname($lock), 0777, true); file_put_contents($lock, '<?php return ' . var_export(['database'=>['dsn'=>"mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4",'user'=>'root','password'=>'']], true) . ';'); }
    public function drop(): void { $this->server->exec("DROP DATABASE IF EXISTS `$this->name`"); }
}
