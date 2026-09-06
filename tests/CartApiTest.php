<?php declare(strict_types=1);
namespace Tests;

use PDO; use Throwable; use VO\Database\MigrationRunner; use VO\Database\PdoConnection; use VO\Installer\InstallerSeeder;

final class CartApiTest extends TestCase
{
    public function testTokenLifecycleAddUpdateRemoveAndPreviewConfirmation(): void
    {
        $s = CartApiScratchDatabase::create(); if ($s === null) return; [$h] = $this->harness($s);
        try {
            $ids = $s->seed();
            $r = $h->json('GET', '/api/cart'); $this->assertSame(200, $r['status']); $this->assertTrue($h->cookieContains('vo_cart='));
            $tokenHash = (string)$s->pdo->query('SELECT token_hash FROM carts LIMIT 1')->fetchColumn(); $this->assertSame(64, strlen($tokenHash));
            $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM carts')->fetchColumn());
            $this->assertSame(200, $h->json('GET', '/api/cart')['status']); $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM carts')->fetchColumn());

            $r = $h->json('POST', '/api/cart/items', ['item_id'=>$ids['burger'], 'variant_id'=>$ids['burger_variant'], 'qty'=>2, 'modifiers'=>[$ids['cheese']]]); $j = $this->ok($r);
            $lineId = (int)$j['data']['cart']['items'][0]['id']; $this->assertSame(2, $j['data']['cart']['items'][0]['qty']);
            $this->assertSame($ids['branch1'], $j['data']['cart']['branch_id']);

            $r = $h->json('PATCH', '/api/cart/items/' . $lineId, ['qty'=>5]); $j = $this->ok($r);
            $this->assertSame(5, $j['data']['cart']['items'][0]['qty']); $this->assertSame(5500, $j['data']['pricing']['gross_items_cents']);

            $r = $h->json('POST', '/api/cart/coupon', ['coupon_code'=>'FIFTY']); $j = $this->ok($r); $this->assertSame(true, $j['data']['applied']);
            $r = $h->json('POST', '/api/cart/coupon', ['coupon_code'=>'BOGUS']); $j = $this->ok($r); $this->assertSame(false, $j['data']['applied']); $this->assertTrue(($j['data']['message'] ?? '') !== '');
            $r = $h->json('POST', '/api/cart/checkout-data', ['fulfillment'=>'pickup','branch_id'=>$ids['branch1'],'payment_method'=>'cash','customer_note'=>'Ring bell','address_json'=>['street'=>'A']]);
            $j = $this->ok($r); $this->assertSame('Ring bell', $j['data']['cart']['customer_note']);

            $r = $h->json('GET', '/api/cart/preview'); $j = $this->ok($r); $this->assertSame(false, $j['data']['pricing']['price_changed']); $this->assertTrue(isset($j['data']['pricing']['minimum_order']));
            $accepted = ['accepted_grand_total_cents'=>$j['data']['pricing']['grand_total_cents'], 'accepted_lines'=>array_map(fn($l) => ['line_total_cents'=>$l['line_total_cents']], $j['data']['pricing']['lines'])];
            $r = $h->json('POST', '/api/cart/preview', $accepted); $j = $this->ok($r); $this->assertSame(true, $j['data']['confirmation_ready']);
            $s->pdo->exec('UPDATE item_variants SET price_cents=2000 WHERE id=' . $ids['burger_variant']);
            $r = $h->json('POST', '/api/cart/preview', $accepted); $j = $this->ok($r); $this->assertSame(true, $j['data']['pricing']['price_changed']);

            $r = $h->json('DELETE', '/api/cart/items/' . $lineId); $j = $this->ok($r); $this->assertSame([], $j['data']['cart']['items']);
            $this->assertSame(404, $h->json('DELETE', '/api/cart/items/' . $lineId)['status']);
        } finally { $h->stop(); $s->drop(); }
    }

    public function testValidationBranchLockUnavailableAndExpiredCart(): void
    {
        $s = CartApiScratchDatabase::create(); if ($s === null) return; [$h] = $this->harness($s);
        try {
            $ids = $s->seed();
            $this->assertSame(422, $h->json('POST','/api/cart/items',['item_id'=>$ids['pizza']])['status']);
            $this->assertSame(422, $h->json('POST','/api/cart/items',['item_id'=>$ids['pizza'],'variant_id'=>$ids['pizza_variant'],'modifiers'=>[$ids['cheese'],$ids['bacon'],$ids['sauce'],$ids['onion']]])['status']);
            $this->assertSame(200, $h->json('POST','/api/cart/items',['item_id'=>$ids['pizza'],'variant_id'=>$ids['pizza_variant'],'modifiers'=>[$ids['cheese']]])['status']);
            $this->assertSame(200, $h->json('POST','/api/cart/items',['item_id'=>$ids['service']])['status']);
            $this->assertSame(409, $h->json('POST','/api/cart/items',['item_id'=>$ids['drink']])['status']);
            $this->assertSame(409, $h->json('POST','/api/cart/checkout-data',['fulfillment'=>'delivery','branch_id'=>$ids['branch1']])['status']);
            $s->pdo->exec('UPDATE catalog_items SET archived_at=NOW() WHERE id=' . $ids['pizza']);
            $j = $this->ok($h->json('GET','/api/cart/preview')); $this->assertSame($ids['pizza'], $j['data']['unavailable_lines'][0]['item_id']);
            $s->pdo->exec("UPDATE carts SET expires_at='2000-01-01 00:00:00'");
            $r = $h->json('GET', '/api/cart'); $j = $this->ok($r); $this->assertSame([], $j['data']['cart']['items']); $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM carts')->fetchColumn());
        } finally { $h->stop(); $s->drop(); }
    }

    private function harness(CartApiScratchDatabase $s): array { $dir = $this->tempDir('cart_api'); $lock = $dir . '/installed.php'; $s->install($lock); $h = new CartApiServer(dirname(__DIR__) . '/public_html', ['VO_INSTALLED_CONFIG_PATH'=>$lock,'VO_STORAGE_PATH'=>$dir . '/storage','VO_HTTP_NO_REDIRECTS'=>'1']); $h->start(); return [$h,$lock]; }
    private function ok(array $r): array { $j = json_decode($r['body'], true); $this->assertSame(true, $j['ok'] ?? null, $r['body']); return $j; }
}

final class CartApiScratchDatabase
{
    private function __construct(public string $name, public PDO $pdo, private PDO $server) {}
    public static function create(): ?self { try { $s = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); } catch (Throwable $e) { throw new \RuntimeException('MariaDB is REQUIRED for CartApiTest.', 0, $e); } $n = 'vo_cart6_test_' . bin2hex(random_bytes(5)); $s->exec("CREATE DATABASE `$n` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $p = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$n;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); return new self($n, $p, $s); }
    public function install(string $lock): void { $db = new PdoConnection("mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4", 'root', ''); (new MigrationRunner($db, dirname(__DIR__) . '/api/database/migrations'))->run(); (new InstallerSeeder($db))->seed(['business_name'=>'Demo','business_slug'=>'demo','branch_name'=>'Centro','timezone'=>'America/Argentina/Buenos_Aires','admin_name'=>'Owner','admin_email'=>'owner@example.test','admin_password'=>'Password123']); if (!is_dir(dirname($lock))) mkdir(dirname($lock), 0777, true); file_put_contents($lock, '<?php return ' . var_export(['database'=>['dsn'=>"mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4",'user'=>'root','password'=>'']], true) . ';'); }
    public function seed(): array
    {
        $b1 = (int)$this->pdo->query('SELECT id FROM branches LIMIT 1')->fetchColumn(); $this->pdo->exec("INSERT INTO branches (business_id,name,is_active) VALUES (1,'Sur',1)"); $b2 = (int)$this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO branch_settings (branch_id,setting_key,setting_value) VALUES ($b1,'minimum_pickup_cents','300'),($b1,'minimum_delivery_cents','2000')");
        $this->pdo->exec("INSERT INTO categories (name,slug) VALUES ('Food','food')");
        $this->pdo->exec("INSERT INTO catalog_items (category_id,type,name,slug,base_price_cents,requires_variant,allows_delivery,is_active) VALUES (1,'product','Pizza','pizza',1000,1,1,1),(1,'service','Massage','massage',2000,0,0,1),(1,'product','Drink','drink',500,0,1,1),(1,'product','Burger','burger',1000,0,1,1)");
        $pizza = (int)$this->pdo->query("SELECT id FROM catalog_items WHERE slug='pizza'")->fetchColumn(); $service = (int)$this->pdo->query("SELECT id FROM catalog_items WHERE slug='massage'")->fetchColumn(); $drink = (int)$this->pdo->query("SELECT id FROM catalog_items WHERE slug='drink'")->fetchColumn(); $burger = (int)$this->pdo->query("SELECT id FROM catalog_items WHERE slug='burger'")->fetchColumn();
        $this->pdo->exec("INSERT INTO item_variants (item_id,name,price_cents,is_active) VALUES ($pizza,'Large',1200,1),($burger,'Default',1000,1)"); $pv=(int)$this->pdo->query("SELECT id FROM item_variants WHERE item_id=$pizza")->fetchColumn(); $bv=(int)$this->pdo->query("SELECT id FROM item_variants WHERE item_id=$burger")->fetchColumn();
        $this->pdo->exec("INSERT INTO branch_items (branch_id,item_id,is_available) VALUES ($b1,$pizza,1),($b1,$service,1),($b2,$drink,1),($b1,$burger,1)"); $this->pdo->exec("INSERT INTO branch_variants (branch_id,variant_id,is_available) VALUES ($b1,$pv,1),($b1,$bv,1)");
        $this->pdo->exec("INSERT INTO modifier_groups (name,is_required,selection,min_select,max_select,is_active) VALUES ('Toppings',0,'multi',0,3,1)"); $g=(int)$this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO modifiers (group_id,name,price_delta_cents,is_active) VALUES ($g,'Cheese',100,1),($g,'Bacon',100,1),($g,'Sauce',100,1),($g,'Onion',100,1)"); $mods=$this->pdo->query("SELECT id,name FROM modifiers")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->pdo->exec("INSERT INTO item_modifier_group (item_id,group_id) VALUES ($pizza,$g),($burger,$g)");
        $this->pdo->exec("INSERT INTO coupons (code,discount_basis_points,min_amount_cents) VALUES ('FIFTY',5000,100)");
        // Delivery coverage zone for branch1 so delivery checkout-data resolves (street 'A', city 'B').
        $this->pdo->exec("INSERT INTO delivery_zones (branch_id,name,match_terms,customer_rate_cents,driver_payout_cents,is_active) VALUES ($b1,'Zona A','a, b',300,150,1)");
        return ['branch1'=>$b1,'branch2'=>$b2,'pizza'=>$pizza,'pizza_variant'=>$pv,'service'=>$service,'drink'=>$drink,'burger'=>$burger,'burger_variant'=>$bv,'cheese'=>(int)array_search('Cheese',$mods,true),'bacon'=>(int)array_search('Bacon',$mods,true),'sauce'=>(int)array_search('Sauce',$mods,true),'onion'=>(int)array_search('Onion',$mods,true)];
    }
    public function drop(): void { $this->server->exec("DROP DATABASE IF EXISTS `$this->name`"); }
}

final class CartApiServer
{
    private $process = null; private int $port; private string $cookie; private array $envKeys = [];
    public function __construct(private string $root, private array $env = []) { $this->port = random_int(20000, 45000); $this->cookie = tempnam(sys_get_temp_dir(), 'vo_cart_cookie_') ?: ''; }
    public function start(): void
    {
        foreach ($this->env + ['VO_INSTALLER_TESTING'=>'1'] as $k=>$v) { $this->envKeys[]=$k; putenv($k . '=' . $v); }
        $this->process = proc_open([PHP_BINARY,'-S','127.0.0.1:' . $this->port,'-t',$this->root,$this->root . '/router.php'], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, dirname($this->root));
        if (!is_resource($this->process)) throw new \RuntimeException('Could not start PHP server.'); $deadline=microtime(true)+5;
        do { if (@file_get_contents('http://127.0.0.1:' . $this->port . '/health', false, stream_context_create(['http'=>['ignore_errors'=>true]])) !== false) return; usleep(100000); } while (microtime(true)<$deadline);
        $this->stop(); throw new \RuntimeException('PHP server did not start.');
    }
    public function json(string $method, string $path, array $data = [], array $extraHeaders = []): array
    {
        $header = "Cookie: " . $this->cookieHeader() . "\r\nContent-Type: application/json\r\n"; foreach ($extraHeaders as $k=>$v) $header .= $k . ': ' . $v . "\r\n";
        $headers = ['ignore_errors'=>true,'method'=>$method,'header'=>$header];
        if ($method !== 'GET') $headers['content'] = json_encode($data);
        $body = file_get_contents('http://127.0.0.1:' . $this->port . $path, false, stream_context_create(['http'=>$headers]));
        foreach ($http_response_header ?? [] as $h) if (stripos($h,'Set-Cookie:')===0) file_put_contents($this->cookie, trim(substr($h,11)) . "\n", FILE_APPEND);
        $code=0; foreach ($http_response_header ?? [] as $h) if (preg_match('/^HTTP\/\S+\s+(\d+)/',$h,$m)) $code=(int)$m[1];
        return ['status'=>$code,'body'=>$body ?: '','headers'=>$http_response_header ?? []];
    }
    public function cookieContains(string $needle): bool { return is_file($this->cookie) && str_contains((string)file_get_contents($this->cookie), $needle); }
    private function cookieHeader(): string { $pairs=[]; foreach (is_file($this->cookie) ? (file($this->cookie, FILE_IGNORE_NEW_LINES) ?: []) : [] as $c) { $pair=explode(';',$c,2)[0]; $pairs[explode('=',$pair,2)[0]]=$pair; } return implode('; ', array_values($pairs)); }
    public function stop(): void { if (is_resource($this->process)) { $s=proc_get_status($this->process); if (($s['pid']??0)>0 && PHP_OS_FAMILY==='Windows') @exec('taskkill /F /T /PID ' . (int)$s['pid'] . ' >NUL 2>&1'); else proc_terminate($this->process); proc_close($this->process); $this->process=null; } foreach (array_unique($this->envKeys) as $k) putenv($k); if ($this->cookie) @unlink($this->cookie); }
    public function __destruct() { $this->stop(); }
}
