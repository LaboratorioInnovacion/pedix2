<?php declare(strict_types=1);
namespace VO\Cart;

use PDO;
use VO\Support\Template;

final class CartController
{
    public function __construct(private CartService $service, private PDO $pdo, private Template $tpl) {}

    public function cart(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') { $this->handleCartPost(); return; }
        $state = $this->state();
        echo $this->tpl->render('cart_page', ['title'=>'Carrito','state'=>$this->hydrate($state),'flash'=>$this->flash()]);
    }

    public function checkout(): void
    {
        $state = $this->state($this->accepted());
        if (!$state['cart']['items']) { $this->setFlash('Tu carrito está vacío.'); $this->redirect('/carrito'); return; }
        echo $this->tpl->render('checkout_page', ['title'=>'Checkout','state'=>$this->hydrate($state),'branches'=>$this->branches(),'flash'=>$this->flash()]);
    }

    public function checkoutData(): void
    {
        $data = $_POST + ['address_json'=>['street'=>$_POST['street'] ?? '', 'city'=>$_POST['city'] ?? '']];
        try { $this->service->setCheckoutData($this->hash(), $data); $this->setFlash('Datos de checkout actualizados.'); }
        catch (CartValidationException $e) { $this->setFlash($e->getMessage()); }
        $this->redirect('/checkout');
    }

    private function handleCartPost(): void
    {
        try {
            $action = (string)($_POST['action'] ?? 'add'); $hash = $this->hash();
            if ($action === 'update') $this->service->updateQty($hash, (int)$_POST['cart_item_id'], (int)$_POST['qty']);
            elseif ($action === 'remove') $this->service->removeItem($hash, (int)$_POST['cart_item_id']);
            else $this->service->addToCart($hash, (int)$_POST['item_id'], isset($_POST['variant_id']) && $_POST['variant_id'] !== '' ? (int)$_POST['variant_id'] : null, (int)($_POST['qty'] ?? 1), array_map('intval', (array)($_POST['modifiers'] ?? [])));
            $this->setFlash('Carrito actualizado.');
        } catch (CartValidationException $e) { $this->setFlash($e->getMessage()); }
        $this->redirect('/carrito');
    }

    private function state(array $accepted = []): array { return $accepted ? $this->service->confirmAttempt($this->hash(), $accepted) : $this->service->get($this->hash()); }
    private function hash(): string { $t = $_COOKIE[CartToken::COOKIE] ?? null; if (!CartToken::valid($t)) { $t = CartToken::issue(); setcookie(CartToken::COOKIE, $t, ['expires'=>time()+7200,'path'=>'/','httponly'=>true,'samesite'=>'Lax']); $_COOKIE[CartToken::COOKIE] = $t; } return CartToken::hash((string)$t); }
    private function hydrate(array $state): array
    {
        $ids = array_column($state['cart']['items'], 'item_id'); if (!$ids) return $state + ['details'=>[]];
        $sql = 'SELECT id,name FROM catalog_items WHERE id IN (' . rtrim(str_repeat('?,', count($ids)), ',') . ')'; $q=$this->pdo->prepare($sql); $q->execute($ids); $names=[]; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $names[(int)$r['id']] = (string)$r['name'];
        $un = array_flip(array_map(fn($r)=>(int)$r['cart_item_id'], $state['unavailable_lines'] ?? [])); $details=[];
        foreach ($state['cart']['items'] as $i=>$line) $details[] = $line + ['name'=>$names[(int)$line['item_id']] ?? 'Ítem', 'unavailable'=>isset($un[(int)$line['id']]), 'pricing'=>$state['pricing']['lines'][$i] ?? null];
        return $state + ['details'=>$details];
    }
    private function accepted(): array { return isset($_GET['accepted_grand_total_cents']) ? ['accepted_grand_total_cents'=>(int)$_GET['accepted_grand_total_cents']] : []; }
    private function branches(): array { return $this->pdo->query('SELECT id,name FROM branches WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    private function setFlash(string $m): void { setcookie('vo_flash', $m, ['expires'=>time()+300,'path'=>'/','samesite'=>'Lax']); }
    private function flash(): string { $m=(string)($_COOKIE['vo_flash'] ?? ''); if ($m !== '') setcookie('vo_flash','', ['expires'=>time()-3600,'path'=>'/','samesite'=>'Lax']); return $m; }
    private function redirect(string $to): void { http_response_code(302); header('Location: ' . $to); }
    public static function money(int $cents): string { return '$ ' . number_format($cents / 100, 2, ',', '.'); }
}
