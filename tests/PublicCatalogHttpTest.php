<?php declare(strict_types=1);
namespace Tests;

use PDO;
use Throwable;
use VO\Database\MigrationRunner;
use VO\Database\PdoConnection;
use VO\Installer\InstallerSeeder;

final class PublicCatalogHttpTest extends TestCase
{
    public function testHomeCategoryDetailSearchAndHealth(): void
    {
        $s = PublicCatalogScratchDatabase::create(); if ($s === null) return;
        [$h] = $this->installedHarness($s, 'public_catalog');
        try {
            $ids = $s->seedCatalog();
            $r = $h->request('GET', '/');
            $this->assertSame(200, $r['status']);
            $this->assertTrue(str_contains($r['body'], 'Catálogo'));
            $this->assertTrue(str_contains($r['body'], 'Pizza &amp; Muzza'));
            $this->assertTrue(!str_contains($r['body'], 'Oculto') && !str_contains($r['body'], 'Archivado') && !str_contains($r['body'], 'Sin stock'));

            $r = $h->request('GET', '/categoria/comidas');
            $this->assertSame(200, $r['status']);
            $this->assertTrue(str_contains($r['body'], 'Pizzas hijas'));
            $this->assertTrue(str_contains($r['body'], 'Pizza &amp; Muzza'));
            $this->assertTrue(!str_contains($r['body'], 'Limonada'));

            $r = $h->request('GET', '/producto/pizza-muzza');
            $this->assertSame(200, $r['status']);
            $this->assertTrue(str_contains($r['body'], 'Grande') && str_contains($r['body'], '$ 9,00'));
            $this->assertTrue(str_contains($r['body'], 'Extras &amp; Salsas') && str_contains($r['body'], 'Picante &lt;suave&gt;'));
            $this->assertTrue(!str_contains($r['body'], 'Finalizar compra'));

            $r = $h->request('GET', '/buscar?q=100%25');
            $this->assertSame(200, $r['status']);
            $this->assertTrue(str_contains($r['body'], 'Limonada 100%'));
            $this->assertTrue(!str_contains($r['body'], 'Pizza &amp; Muzza'));

            $this->assertSame(404, $h->request('GET', '/producto/archivado')['status']);
            $this->assertSame(404, $h->request('GET', '/producto/oculto')['status']);
            $before = (int)$s->pdo->query('SELECT COUNT(*) FROM catalog_items')->fetchColumn();
            $this->assertSame(404, $h->request('POST', '/buscar?q=100%25')['status']);
            $this->assertSame($before, (int)$s->pdo->query('SELECT COUNT(*) FROM catalog_items')->fetchColumn());

            $health = $h->request('GET', '/health');
            $this->assertSame(200, $health['status']);
            $json = json_decode($health['body'], true);
            $this->assertSame(true, $json['ok'] ?? null);
            $this->assertSame(['status'=>'ok','app'=>'Vender Online'], $json['data'] ?? null);
            $this->assertTrue(array_key_exists('request_id', $json));
        } finally { $h->stop(); $s->drop(); }
    }

    private function installedHarness(PublicCatalogScratchDatabase $s, string $name): array { $dir = $this->tempDir($name); $lock = $dir . '/installed.php'; $s->install($lock); $h = new \InstallerTestServer(dirname(__DIR__) . '/public_html', ['VO_INSTALLED_CONFIG_PATH'=>$lock,'VO_STORAGE_PATH'=>$dir . '/storage','VO_HTTP_NO_REDIRECTS'=>'1']); $h->start(); return [$h,$lock]; }
}

final class PublicCatalogScratchDatabase
{
    private function __construct(public string $name, public PDO $pdo, private PDO $server) {}
    public static function create(): ?self { try { $s = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); } catch (Throwable $e) { echo 'SKIP MariaDB unavailable: ' . $e->getMessage() . PHP_EOL; return null; } $n = 'vo_cat_test_' . bin2hex(random_bytes(5)); $s->exec("CREATE DATABASE `$n` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $p = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$n;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); return new self($n, $p, $s); }
    public function install(string $lock): void { $db = new PdoConnection("mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4", 'root', ''); (new MigrationRunner($db, dirname(__DIR__) . '/api/database/migrations'))->run(); (new InstallerSeeder($db))->seed(['business_name'=>'Demo Store','business_slug'=>'demo-store','branch_name'=>'Centro','timezone'=>'America/Argentina/Buenos_Aires','admin_name'=>'Dueño','admin_email'=>'owner@example.test','admin_password'=>'Password123']); if (!is_dir(dirname($lock))) mkdir(dirname($lock), 0777, true); file_put_contents($lock, '<?php return ' . var_export(['database'=>['dsn'=>"mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4",'user'=>'root','password'=>'']], true) . ';'); }
    public function seedCatalog(): array
    {
        $branch = (int)$this->pdo->query('SELECT id FROM branches LIMIT 1')->fetchColumn();
        $this->pdo->exec("INSERT INTO categories (name,slug,is_active) VALUES ('Comidas','comidas',1),('Bebidas','bebidas',1)");
        $parent = (int)$this->pdo->query("SELECT id FROM categories WHERE slug='comidas'")->fetchColumn();
        $this->pdo->exec("INSERT INTO categories (parent_id,name,slug,is_active) VALUES ($parent,'Pizzas hijas','pizzas',1)");
        $child = (int)$this->pdo->query("SELECT id FROM categories WHERE slug='pizzas'")->fetchColumn();
        $bebidas = (int)$this->pdo->query("SELECT id FROM categories WHERE slug='bebidas'")->fetchColumn();
        $this->pdo->exec("INSERT INTO catalog_items (category_id,type,name,slug,description,base_price_cents,requires_variant,is_active) VALUES ($child,'product','Pizza & Muzza','pizza-muzza','Queso <doble>',1200,1,1),($bebidas,'product','Limonada 100%','limonada-100','Fría',500,0,1),($child,'product','Oculto','oculto','No ver',100,0,0),($child,'product','Archivado','archivado','No ver',100,0,1),($child,'product','Sin stock','sin-stock','No ver',100,0,1)");
        $pizza = (int)$this->pdo->query("SELECT id FROM catalog_items WHERE slug='pizza-muzza'")->fetchColumn();
        $limonada = (int)$this->pdo->query("SELECT id FROM catalog_items WHERE slug='limonada-100'")->fetchColumn();
        $arch = (int)$this->pdo->query("SELECT id FROM catalog_items WHERE slug='archivado'")->fetchColumn();
        $this->pdo->exec("UPDATE catalog_items SET archived_at=NOW() WHERE id=$arch");
        $this->pdo->exec("INSERT INTO branch_items (branch_id,item_id,is_available,price_override_cents,stock_mode) VALUES ($branch,$pizza,1,1000,'unlimited'),($branch,$limonada,1,NULL,'unlimited')");
        $this->pdo->exec("INSERT INTO item_variants (item_id,name,price_cents,is_active,sort_order) VALUES ($pizza,'Grande',1100,1,0),($pizza,'Chica',900,1,1)");
        $variant = (int)$this->pdo->query("SELECT id FROM item_variants WHERE name='Grande'")->fetchColumn();
        $this->pdo->exec("INSERT INTO branch_variants (branch_id,variant_id,is_available,price_override_cents) VALUES ($branch,$variant,1,900)");
        $this->pdo->exec("INSERT INTO modifier_groups (name,is_required,selection,min_select,max_select,is_active) VALUES ('Extras & Salsas',0,'multi',0,2,1)");
        $group = (int)$this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO modifiers (group_id,name,price_delta_cents,is_active) VALUES ($group,'Picante <suave>',150,1)");
        $this->pdo->exec("INSERT INTO item_modifier_group (item_id,group_id,sort_order) VALUES ($pizza,$group,0)");
        return ['branch'=>$branch,'pizza'=>$pizza];
    }
    public function drop(): void { $this->server->exec("DROP DATABASE IF EXISTS `$this->name`"); }
}
