<?php declare(strict_types=1);
namespace VO\Orders;

use Throwable; use VO\Delivery\DeliveryService; use VO\Notifications\NotificationService; use VO\Payments\PaymentRepository; use VO\Payments\PaymentService; use VO\Payments\ProofStorage; use VO\Settings\SettingsRepository; use VO\Support\Template;

final class OrderPageController
{
    public function __construct(private OrderRepository $repo, private Template $tpl, private PaymentRepository $payments, private PaymentService $service, private SettingsRepository $settings, private ProofStorage $proofs, private OrderOperationsService $operations, private ?DeliveryService $delivery = null, private ?NotificationService $notifications = null) {}

    public function show(string $token, ?string $message = null, ?string $error = null): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) { $this->notFound(); return; }
        $order = $this->repo->findByPublicKey($token);
        if (!$order) { $this->notFound(); return; }
        try { $this->operations->expireStaleLazy(); } catch (Throwable) { /* sweep is best-effort */ }
        try { $this->notifications?->dispatchPendingLazy((int)$order['business_id']); } catch (Throwable) { /* notification sweep is best-effort (spec N3) */ }
        $payment = null; $method = (string)($order['payment_method'] ?? '');
        if (in_array($method, ['transfer','mercadopago'], true)) {
            try { $this->service->expireStaleLazy(); $payment = $this->service->initiateForOrder((int)$order['id'], $method); }
            catch (Throwable) { $payment = $this->payments->getByOrderId((int)$order['id']); }
        }
        // Delivery section for delivery orders only (spec D9): state + courier + PIN gated inside the service.
        $delivery = null;
        if ($this->delivery !== null && (string)($order['fulfillment'] ?? '') === 'delivery') {
            try { $delivery = $this->delivery->publicContextForOrder($order); } catch (Throwable) { $delivery = null; }
        }
        echo $this->tpl->render('order_confirmation', ['title'=>'Pedido ' . $order['number'], 'order'=>$order, 'items'=>$this->repo->publicItems((int)$order['id']), 'address'=>$this->repo->publicAddress((int)$order['id']), 'payment'=>$payment, 'transferInstructions'=>$this->settings->get((int)$order['business_id'],'transfer_instructions'), 'message'=>$message, 'error'=>$error, 'delivery'=>$delivery]);
    }

    public function uploadProof(string $token, array $file): void
    {
        $order = preg_match('/^[a-f0-9]{64}$/',$token) ? $this->repo->findByPublicKey($token) : null;
        if (!$order || (string)$order['payment_method'] !== 'transfer') { $this->notFound(); return; }
        try {
            $payment=$this->service->initiateForOrder((int)$order['id'],'transfer');
            if ((string)$payment['state'] !== 'pending_verification') throw new \RuntimeException('PROOF_UPLOAD_CLOSED');
            $path=$this->proofs->store($file,(int)$order['business_id'],(string)$order['number']);
            $this->payments->attachProof((int)$payment['id'],$path); $this->show($token,'Comprobante recibido.');
        } catch (Throwable $e) { http_response_code(422); $this->show($token,null,'El comprobante debe ser JPG, PNG o PDF y pesar hasta 5 MB.'); }
    }

    public function notFound(): void { http_response_code(404); echo $this->tpl->render('order_confirmation', ['title'=>'Pedido no encontrado','order'=>null,'items'=>[],'address'=>null,'payment'=>null,'message'=>null,'error'=>null,'transferInstructions'=>null,'delivery'=>null]); }
}
