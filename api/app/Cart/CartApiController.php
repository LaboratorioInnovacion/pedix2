<?php declare(strict_types=1);
namespace VO\Cart;

use InvalidArgumentException; use VO\Domain\IdempotencyConflictException; use VO\Delivery\DeliveryUnavailableException; use VO\Http\JsonResponse; use VO\Http\Request; use VO\Inventory\InsufficientStockException; use VO\Orders\EmptyCartException; use VO\Orders\OrderService; use VO\Orders\PriceChangedException;

final class CartApiController
{
    private bool $issued=false; private string $token;
    public function __construct(private CartService $service, private ?OrderService $orders = null) {}
    public function get(Request $r): JsonResponse { return $this->wrap($r, fn($h)=>$this->service->get($h)); }
    public function add(Request $r): JsonResponse { $d=$this->body(); return $this->wrap($r, fn($h)=>$this->service->addToCart($h,(int)($d['item_id']??0),isset($d['variant_id'])?(int)$d['variant_id']:null,(int)($d['qty']??1),$d['modifiers']??[])); }
    public function qty(Request $r): JsonResponse { $d=$this->body(); return $this->wrap($r, fn($h)=>$this->service->updateQty($h,(int)$r->attribute('id'),(int)($d['qty']??0))); }
    public function delete(Request $r): JsonResponse { return $this->wrap($r, fn($h)=>$this->service->removeItem($h,(int)$r->attribute('id'))); }
    public function coupon(Request $r): JsonResponse { $d=$this->body(); return $this->wrap($r, fn($h)=>$this->service->applyCoupon($h,(string)($d['coupon_code']??''))); }
    public function checkout(Request $r): JsonResponse { $d=$this->body(); return $this->wrap($r, fn($h)=>$this->service->setCheckoutData($h,$d)); }
    public function preview(Request $r): JsonResponse { $d=$this->body(); return $this->wrap($r, fn($h)=>$d ? $this->service->confirmAttempt($h,$d) : $this->service->get($h)); }
    public function confirm(Request $r): JsonResponse
    {
        $d=$this->body(); $rid=$r->attribute('request_id');
        try {
            if ($this->orders === null) throw new InvalidArgumentException('Order service is unavailable.');
            $order=$this->orders->createFromCart($this->hash($r), trim((string)($d['idempotency_key'] ?? '')), is_array($d['accepted_totals'] ?? null) ? $d['accepted_totals'] : $d, is_array($d['customer'] ?? null) ? $d['customer'] : []);
            $res=new JsonResponse(['ok'=>true,'order'=>$order,'request_id'=>$rid]);
        } catch (PriceChangedException $e) { $res=new JsonResponse(['ok'=>false,'error'=>'price_changed','breakdown'=>$e->current,'request_id'=>$rid],409);
        } catch (InsufficientStockException $e) { $res=JsonResponse::error('insufficient_stock',$e->getMessage(),409,$rid);
        } catch (EmptyCartException|InvalidArgumentException $e) { $res=JsonResponse::error('validation',$e->getMessage(),422,$rid);
        } catch (IdempotencyConflictException $e) { $res=JsonResponse::error('idempotency_conflict',$e->getMessage(),409,$rid); }
        return $this->issued ? $res->withHeader('Set-Cookie', CartToken::cookie($this->token)) : $res;
    }
    private function wrap(Request $r, callable $fn): JsonResponse { try { $hash=$this->hash($r); $res=JsonResponse::ok($fn($hash), $r->attribute('request_id')); return $this->issued ? $res->withHeader('Set-Cookie', CartToken::cookie($this->token)) : $res; } catch (DeliveryUnavailableException $e) { $res=JsonResponse::error(DeliveryUnavailableException::CODE,$e->getMessage(),$e->status,$r->attribute('request_id')); return $this->issued ? $res->withHeader('Set-Cookie', CartToken::cookie($this->token)) : $res; } catch (CartValidationException $e) { $res=JsonResponse::error('cart_error',$e->getMessage(),$e->status,$r->attribute('request_id')); return $this->issued ? $res->withHeader('Set-Cookie', CartToken::cookie($this->token)) : $res; } }
    private function hash(Request $r): string { $t=$r->cookie(CartToken::COOKIE); if (!CartToken::valid($t)) { $this->token=CartToken::issue(); $this->issued=true; return CartToken::hash($this->token); } $this->token=(string)$t; return CartToken::hash((string)$t); }
    private function body(): array { $raw=(string)file_get_contents('php://input'); $json=json_decode($raw,true); if (is_array($json)) return $json; parse_str($raw,$form); return is_array($form)?$form:[]; }
}
