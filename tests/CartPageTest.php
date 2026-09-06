<?php declare(strict_types=1);
namespace Tests;

final class CartPageTest extends TestCase
{
    public function testCartPageRendersItemsEmptyAndExpiredStates(): void
    {
        $s = CartApiScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $ids = $s->seed();
            $this->assertSame(200, $h->json('POST','/api/cart/items',['item_id'=>$ids['burger'],'variant_id'=>$ids['burger_variant'],'qty'=>2,'modifiers'=>[$ids['cheese']]])['status']);
            $r = $h->json('GET','/carrito');
            $this->assertSame(200, $r['status']);
            $this->assertTrue(str_contains($r['body'], 'Burger'));
            $this->assertTrue(str_contains($r['body'], 'data-cart-item-id') && str_contains($r['body'], 'Actualizar') && str_contains($r['body'], 'Quitar'));

            $s2 = CartApiScratchDatabase::create(); [$h2] = $this->harness($s2);
            try { $s2->seed(); $e = $h2->json('GET','/carrito'); $this->assertSame(200, $e['status']); $this->assertTrue(str_contains($e['body'], 'Tu carrito está vacío') && str_contains($e['body'], 'Ver catálogo')); } finally { $h2->stop(); $s2->drop(); }

            $s->pdo->exec("UPDATE carts SET expires_at='2000-01-01 00:00:00'");
            $x = $h->json('GET','/carrito');
            $this->assertSame(200, $x['status']);
            $this->assertTrue(str_contains($x['body'], 'Tu carrito está vacío'));
        } finally { $h->stop(); $s->drop(); }
    }

    public function testServerFormAddAndCheckoutPreviewFlow(): void
    {
        $s = CartApiScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $ids = $s->seed();
            $r = $h->form('POST','/carrito',['action'=>'add','item_id'=>$ids['burger'],'variant_id'=>$ids['burger_variant'],'qty'=>1,'modifiers'=>[$ids['cheese']]]);
            $this->assertSame(302, $r['status']);
            $this->assertTrue(str_contains($h->json('GET','/carrito')['body'], 'Burger'));
            $c = $h->json('GET','/checkout');
            $this->assertSame(200, $c['status']);
            foreach (['Resumen del carrito','name="fulfillment"','name="payment_method"','name="customer_note"','data-address-fields','Detalle de precios'] as $needle) $this->assertTrue(str_contains($c['body'], $needle), $needle);

            $empty = CartApiScratchDatabase::create(); [$eh] = $this->harness($empty);
            try { $empty->seed(); $this->assertSame(302, $eh->json('GET','/checkout')['status']); } finally { $eh->stop(); $empty->drop(); }

            $p = $h->form('POST','/checkout-data',['fulfillment'=>'delivery','branch_id'=>$ids['branch1'],'payment_method'=>'cash','customer_note'=>'Tocar timbre','street'=>'A','city'=>'B']);
            $this->assertSame(302, $p['status']);
            $body = $h->json('GET','/checkout')['body'];
            foreach (['Cupón','Descuento por pago','Envío','Total','Tocar timbre'] as $needle) $this->assertTrue(str_contains($body, $needle), $needle);
        } finally { $h->stop(); $s->drop(); }
    }

    public function testPriceChangedAndUnavailableWarningsAreServerRendered(): void
    {
        $s = CartApiScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $ids = $s->seed();
            $this->assertSame(200, $h->json('POST','/api/cart/items',['item_id'=>$ids['burger'],'variant_id'=>$ids['burger_variant'],'qty'=>1,'modifiers'=>[]])['status']);
            $j = json_decode($h->json('GET','/api/cart/preview')['body'], true);
            $accepted = (string)$j['data']['pricing']['grand_total_cents'];
            $s->pdo->exec('UPDATE item_variants SET price_cents=2000 WHERE id=' . $ids['burger_variant']);
            $body = $h->json('GET','/checkout?accepted_grand_total_cents=' . $accepted)['body'];
            $this->assertTrue(str_contains($body, 'Los precios se actualizaron desde tu última revisión'));
            $s->pdo->exec('UPDATE catalog_items SET archived_at=NOW() WHERE id=' . $ids['burger']);
            $cart = $h->json('GET','/carrito')['body'];
            $this->assertTrue(str_contains($cart, 'ya no está disponible'));
        } finally { $h->stop(); $s->drop(); }
    }

    private function harness(CartApiScratchDatabase $s): array { $dir = $this->tempDir('cart_page'); $lock = $dir . '/installed.php'; $s->install($lock); $h = new CartPageServer(dirname(__DIR__) . '/public_html', ['VO_INSTALLED_CONFIG_PATH'=>$lock,'VO_STORAGE_PATH'=>$dir . '/storage','VO_HTTP_NO_REDIRECTS'=>'1']); $h->start(); return [$h,$lock]; }
}

final class CartPageServer
{
    private $process = null; private int $port; private string $cookie; private array $envKeys = [];
    public function __construct(private string $root, private array $env = []) { $this->port = random_int(20000, 45000); $this->cookie = tempnam(sys_get_temp_dir(), 'vo_cart_page_cookie_') ?: ''; }
    public function start(): void
    {
        foreach ($this->env + ['VO_INSTALLER_TESTING'=>'1'] as $k=>$v) { $this->envKeys[]=$k; putenv($k . '=' . $v); }
        $this->process = proc_open([PHP_BINARY,'-S','127.0.0.1:' . $this->port,'-t',$this->root,$this->root . '/router.php'], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, dirname($this->root));
        if (!is_resource($this->process)) throw new \RuntimeException('Could not start PHP server.'); $deadline=microtime(true)+5;
        do { if (@file_get_contents($this->url('/health'), false, stream_context_create(['http'=>['ignore_errors'=>true]])) !== false) return; usleep(100000); } while (microtime(true)<$deadline);
        $this->stop(); throw new \RuntimeException('PHP server did not start.');
    }
    public function json(string $method, string $path, array $data = []): array
    {
        $headers = ['ignore_errors'=>true,'method'=>$method,'follow_location'=>0,'max_redirects'=>0,'header'=>"Cookie: " . $this->cookieHeader() . "\r\nContent-Type: application/json\r\n"];
        if ($method !== 'GET') $headers['content'] = json_encode($data);
        return $this->send($path, $headers);
    }
    public function form(string $method, string $path, array $data = []): array
    {
        $headers = ['ignore_errors'=>true,'method'=>$method,'follow_location'=>0,'max_redirects'=>0,'header'=>"Cookie: " . $this->cookieHeader() . "\r\nContent-Type: application/x-www-form-urlencoded\r\n",'content'=>http_build_query($data)];
        return $this->send($path, $headers);
    }
    private function send(string $path, array $headers): array
    {
        $body = file_get_contents($this->url($path), false, stream_context_create(['http'=>$headers]));
        foreach ($http_response_header ?? [] as $h) if (stripos($h,'Set-Cookie:')===0) file_put_contents($this->cookie, trim(substr($h,11)) . "\n", FILE_APPEND);
        $code=0; foreach ($http_response_header ?? [] as $h) if (preg_match('/^HTTP\/\S+\s+(\d+)/',$h,$m)) $code=(int)$m[1];
        return ['status'=>$code,'body'=>$body ?: '','headers'=>$http_response_header ?? []];
    }
    private function url(string $path): string { return 'http://127.0.0.1:' . $this->port . $path; }
    private function cookieHeader(): string { $pairs=[]; foreach (is_file($this->cookie) ? (file($this->cookie, FILE_IGNORE_NEW_LINES) ?: []) : [] as $c) { $pair=explode(';',$c,2)[0]; $pairs[explode('=',$pair,2)[0]]=$pair; } return implode('; ', array_values($pairs)); }
    public function stop(): void { if (is_resource($this->process)) { $s=proc_get_status($this->process); proc_terminate($this->process); usleep(150000); if (($s['pid']??0)>0 && PHP_OS_FAMILY==='Windows') @exec('taskkill /F /T /PID ' . (int)$s['pid']); proc_close($this->process); $this->process=null; } foreach (array_unique($this->envKeys) as $k) putenv($k); if ($this->cookie) @unlink($this->cookie); }
    public function __destruct() { $this->stop(); }
}
