<?php declare(strict_types=1);
namespace VO\Orders;

use VO\Support\Template;

final class OrderPageController
{
    public function __construct(private OrderRepository $repo, private Template $tpl) {}

    public function show(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) { $this->notFound(); return; }
        $order = $this->repo->findByPublicKey($token);
        if (!$order) { $this->notFound(); return; }
        echo $this->tpl->render('order_confirmation', ['title'=>'Pedido ' . $order['number'], 'order'=>$order, 'items'=>$this->repo->publicItems((int)$order['id']), 'address'=>$this->repo->publicAddress((int)$order['id'])]);
    }

    public function notFound(): void { http_response_code(404); echo $this->tpl->render('order_confirmation', ['title'=>'Pedido no encontrado','order'=>null,'items'=>[],'address'=>null]); }
}
