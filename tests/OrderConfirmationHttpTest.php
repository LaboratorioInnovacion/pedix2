<?php declare(strict_types=1);
namespace Tests;

final class OrderConfirmationHttpTest extends TestCase
{
    public function testHttpConfirmCreatesIdempotentOrderAndPublicTokenPage(): void
    {
        $s = CartApiScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $ids = $s->seed();
            $this->assertSame(200, $h->json('POST','/api/cart/items',['item_id'=>$ids['burger'],'variant_id'=>$ids['burger_variant'],'qty'=>2,'modifiers'=>[$ids['cheese']]])['status']);
            $this->assertSame(200, $h->json('POST','/api/cart/checkout-data',['fulfillment'=>'pickup','branch_id'=>$ids['branch1'],'payment_method'=>'cash','customer_note'=>'Sin cebolla'])['status']);
            $preview = $this->ok($h->json('GET','/api/cart/preview'))['data']['pricing'];
            $accepted = ['accepted_grand_total_cents'=>$preview['grand_total_cents'], 'accepted_lines'=>array_map(fn($l)=>['line_total_cents'=>$l['line_total_cents']], $preview['lines'])];

            $body = ['idempotency_key'=>'confirm-key-1','accepted_totals'=>$accepted,'customer'=>['name'=>'Ada Lovelace','phone'=>'11223344','email'=>'ada@example.test','password'=>'Secret123']];
            $first = $this->json($h->json('POST','/api/cart/confirm',$body));
            $this->assertSame(true, $first['ok']);
            $this->assertSame('pending', $first['order']['status']);
            $this->assertSame($preview['grand_total_cents'], $first['order']['grand_total_cents']);
            $this->assertSame($preview['grand_total_cents'], $first['order']['grand_total']);
            $this->assertSame(64, strlen($first['order']['public_token']));
            $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
            $this->assertSame(0, (int)$s->pdo->query('SELECT COUNT(*) FROM cart_items')->fetchColumn());
            $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM customers WHERE email="ada@example.test"')->fetchColumn());
            $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM orders o JOIN customers c ON c.id=o.customer_id WHERE c.email="ada@example.test"')->fetchColumn());
            $this->assertSame('Ada Lovelace', (string)$s->pdo->query('SELECT customer_name FROM orders LIMIT 1')->fetchColumn());

            $second = $this->json($h->json('POST','/api/cart/confirm',$body));
            $this->assertSame($first['order'], $second['order']);
            $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());

            $page = $h->json('GET','/pedido/' . $first['order']['public_token']);
            $this->assertSame(200, $page['status']);
            foreach ([$first['order']['number'],'Burger','Ada Lovelace','11223344','ada@example.test','Pendiente','Total'] as $needle) $this->assertTrue(str_contains($page['body'], $needle), $needle);
            $this->assertSame(404, $h->json('GET','/pedido/' . $first['order']['number'])['status']);
            $this->assertSame(404, $h->json('GET','/pedido/' . str_repeat('a', 64))['status']);
        } finally { $h->stop(); $s->drop(); }
    }

    public function testConfirmValidationPriceChangedEmptyCartAndCheckoutWiring(): void
    {
        $s = CartApiScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $ids = $s->seed();
            $this->assertSame(200, $h->json('POST','/api/cart/items',['item_id'=>$ids['burger'],'variant_id'=>$ids['burger_variant'],'qty'=>1,'modifiers'=>[]])['status']);
            $preview = $this->ok($h->json('GET','/api/cart/preview'))['data']['pricing'];
            $accepted = ['accepted_grand_total_cents'=>$preview['grand_total_cents'], 'accepted_lines'=>array_map(fn($l)=>['line_total_cents'=>$l['line_total_cents']], $preview['lines'])];

            $missing = $this->json($h->json('POST','/api/cart/confirm',['accepted_totals'=>$accepted]));
            $this->assertSame(422, $missing['status']);
            $s->pdo->exec('UPDATE item_variants SET price_cents=2000 WHERE id=' . $ids['burger_variant']);
            $stale = $this->json($h->json('POST','/api/cart/confirm',['idempotency_key'=>'stale-key','accepted_totals'=>$accepted]));
            $this->assertSame(409, $stale['status']);
            $this->assertSame(false, $stale['ok']);
            $this->assertSame('price_changed', $stale['error']);
            $this->assertTrue(isset($stale['breakdown']['grand_total_cents']));
            $this->assertSame(0, (int)$s->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
            $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM cart_items')->fetchColumn());

            $checkout = $h->json('GET','/checkout')['body'];
            foreach (['data-confirm-order','/api/cart/confirm','sessionStorage','data-accepted-totals','data-confirm-message','data-confirm-breakdown'] as $needle) $this->assertTrue(str_contains($checkout, $needle), $needle);
            $js = (string)file_get_contents(dirname(__DIR__) . '/public_html/assets/js/shop/cart.js');
            foreach (['/api/cart/confirm','sessionStorage','window.location.href = `/pedido/${json.order.public_token}`','price_changed','renderBreakdown'] as $needle) $this->assertTrue(str_contains($js, $needle), $needle);
        } finally { $h->stop(); $s->drop(); }

        $empty = CartApiScratchDatabase::create(); [$eh] = $this->harness($empty);
        try { $empty->seed(); $r = $this->json($eh->json('POST','/api/cart/confirm',['idempotency_key'=>'empty','accepted_totals'=>['accepted_grand_total_cents'=>0]])); $this->assertSame(422, $r['status']); }
        finally { $eh->stop(); $empty->drop(); }
    }

    private function harness(CartApiScratchDatabase $s): array { $dir = $this->tempDir('order_confirm'); $lock = $dir . '/installed.php'; $s->install($lock); $h = new CartApiServer(dirname(__DIR__) . '/public_html', ['VO_INSTALLED_CONFIG_PATH'=>$lock,'VO_STORAGE_PATH'=>$dir . '/storage','VO_HTTP_NO_REDIRECTS'=>'1']); $h->start(); return [$h,$lock]; }
    private function ok(array $r): array { $j = $this->json($r); $this->assertSame(true, $j['ok'] ?? null, $r['body']); return $j; }
    private function json(array $r): array { $j=json_decode($r['body'], true); if (!is_array($j)) $j=[]; return $j + ['status'=>$r['status']]; }
}
