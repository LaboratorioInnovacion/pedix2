<?php declare(strict_types=1);
namespace VO\Payments;

use DomainException; use VO\Audit\AuditService; use VO\Database\Connection; use VO\Domain\InvalidTransition; use VO\Inventory\StockService; use VO\Notifications\NotificationService; use VO\Orders\OrderOperationsService; use VO\Orders\OrderRepository;

final class PaymentService
{
    private const DEFAULT_EXPIRY_HOURS = 48;

    public function __construct(private Connection $db, private PaymentRepository $payments, private OrderRepository $orders, private ?AuditService $audit = null, private ?string $requestId = null, private ?MpClient $mp = null, private ?string $publicBaseUrl = null, private ?NotificationService $notifications = null) {}

    public function initiateForOrder(int $orderId, string $method): array
    {
        $order = $this->orders->getById($orderId); if (!$order) throw new DomainException('ORDER_NOT_FOUND');
        $expected = (string)($order['payment_method'] ?? '');
        if ($expected === 'cash' || $method === 'cash') throw new DomainException('CASH_ORDERS_HAVE_NO_PAYMENT');
        if (!in_array($method, ['transfer','mercadopago'], true) || $method !== $expected) throw new DomainException('PAYMENT_METHOD_MISMATCH');
        if ($existing = $this->payments->getByOrderId($orderId)) return $existing;
        $state = $method === 'transfer' ? 'pending_verification' : 'pending';
        $payment = $this->payments->insert(['order_id'=>$orderId,'business_id'=>(int)$order['business_id'],'method'=>$method,'state'=>$state,'amount_cents'=>(int)$order['grand_total_cents'],'currency'=>'ARS','expires_at'=>date('Y-m-d H:i:s', time() + self::DEFAULT_EXPIRY_HOURS * 3600)]);
        if ($method !== 'mercadopago') return $payment;
        $businessId = (int)$order['business_id']; $enabled = $this->payments->setting($businessId, 'mp_enabled') === '1'; $token = $this->payments->setting($businessId, 'mp_access_token');
        if (!$enabled || !$token) return $payment;
        $external = (string)$payment['id']; $client = $this->mp ?? new MpClient(getenv('VO_MP_BASE_URL') ?: 'https://api.mercadopago.com', $token);
        $pref = $client->createPreference($this->preferencePayload($order, $payment, $external));
        return $this->payments->updatePreference((int)$payment['id'], $external, isset($pref['id']) ? (string)$pref['id'] : null, isset($pref['init_point']) ? (string)$pref['init_point'] : null);
    }

    public function handleMpWebhook(string $rawBody, ?string $signatureHeader, ?string $requestIdHeader): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || ($payload['type'] ?? '') !== 'payment') {
            return ['received' => true, 'ignored' => true];
        }

        $providerPaymentId = (string) ($payload['data']['id'] ?? '');
        if ($providerPaymentId === '') {
            return ['received' => true, 'ignored' => true];
        }

        $businessId = $this->payments->firstBusinessId();
        $secret = $this->payments->setting($businessId, 'mp_webhook_secret') ?? '';
        if (!$this->validSignature($signatureHeader ?? '', $requestIdHeader ?? '', $providerPaymentId, $secret)) {
            throw new DomainException('INVALID_MP_SIGNATURE');
        }

        $token = $this->payments->setting($businessId, 'mp_access_token') ?? '';
        $client = $this->mp ?? new MpClient(getenv('VO_MP_BASE_URL') ?: 'https://api.mercadopago.com', $token);
        $provider = $client->getPayment($providerPaymentId);
        $externalReference = isset($provider['external_reference']) ? (string) $provider['external_reference'] : null;
        $payment = $externalReference ? $this->payments->getByExternalReference($externalReference) : null;
        $providerStatus = (string) ($provider['status'] ?? '');
        $targetState = $this->mpState($providerStatus);
        $providerEventId = isset($payload['id']) && (string) $payload['id'] !== ''
            ? (string) $payload['id']
            : ($requestIdHeader ?: null);

        return $this->db->transaction(function () use ($payment, $businessId, $providerEventId, $externalReference, $provider, $targetState, $providerPaymentId, $providerStatus): array {
            $isNew = $this->payments->insertMpEvent(
                $payment ? (int) $payment['id'] : null,
                $payment ? (int) $payment['business_id'] : $businessId,
                'mp_webhook',
                $providerEventId,
                $externalReference,
                $this->sanitizeProviderPayload($provider),
                $targetState
            );
            if (!$isNew) {
                return ['received' => true, 'duplicate' => true];
            }
            if (!$payment) {
                return ['received' => true, 'state' => $targetState, 'matched' => false];
            }

            $currentState = (string) $payment['state'];
            if ($targetState === 'pending' || $currentState !== 'pending') {
                return ['received' => true, 'state' => $currentState];
            }

            return $this->transitionWithinTransaction(
                (int) $payment['id'],
                $targetState,
                [
                    'provider_payment_id' => $providerPaymentId,
                    'provider_status' => $providerStatus,
                    'provider_status_detail' => isset($provider['status_detail']) ? (string) $provider['status_detail'] : null,
                ],
                ['provider' => 'mercadopago']
            );
        });
    }

    public function verifyTransfer(int $paymentId, int $adminUserId): array
    {
        return $this->transition($paymentId, 'verified', ['verified_by'=>$adminUserId,'verified_at'=>date('Y-m-d H:i:s')], ['actor_id'=>$adminUserId]);
    }

    public function rejectPayment(int $paymentId, int $adminUserId, string $reason): array
    {
        return $this->transition($paymentId, 'rejected', [], ['actor_id'=>$adminUserId,'reason'=>$reason]);
    }

    public function cancelPayment(int $paymentId, ?int $actorId = null, string $reason = 'cancelled'): array
    {
        return $this->transition($paymentId, 'cancelled', [], ['actor_id'=>$actorId,'reason'=>$reason]);
    }

    /** Cancel inside a caller-owned transaction (order gateway/sweep hold it); never opens one itself. */
    public function cancelPaymentWithinTransaction(int $paymentId, ?int $actorId = null, string $reason = 'cancelled'): array
    {
        return $this->transitionWithinTransaction($paymentId, 'cancelled', [], ['actor_id'=>$actorId,'reason'=>$reason]);
    }

    public function expireStaleLazy(int $hours = self::DEFAULT_EXPIRY_HOURS): int
    {
        $count = 0;
        foreach ($this->payments->findStalePending($hours) as $payment) { $this->transition((int)$payment['id'], 'cancelled', [], ['reason'=>'expired']); $count++; }
        return $count;
    }

    public function autoAcceptOrder(int $orderId): array
    {
        return $this->operations()->acceptFromPayment($orderId);
    }

    /** Built lazily to avoid a constructor cycle: the gateway also takes a PaymentService. */
    private function operations(): OrderOperationsService
    {
        return new OrderOperationsService($this->db, $this->orders, new StockService($this->db), $this->payments, $this, $this->audit, $this->requestId, null, $this->notifications);
    }

    private function preferencePayload(array $order, array $payment, string $external): array
    {
        $base = rtrim($this->publicBaseUrl ?: (getenv('VO_PUBLIC_BASE_URL') ?: ''), '/'); $back = $base . '/pedido/' . (string)$order['public_token'];
        $items = array_map(fn($i)=>['title'=>(string)$i['item_name'],'quantity'=>(int)$i['quantity'],'unit_price'=>$this->decimal((float)$i['line_total_cents'] / max(1, (int)$i['quantity'])),'currency_id'=>'ARS'], $this->payments->orderItems((int)$order['id']));
        if (!$items) $items = [['title'=>'Order ' . $order['number'],'quantity'=>1,'unit_price'=>$this->decimal((int)$payment['amount_cents']),'currency_id'=>'ARS']];
        return ['items'=>$items,'external_reference'=>$external,'back_urls'=>['success'=>$back,'failure'=>$back,'pending'=>$back],'notification_url'=>$base . '/api/webhooks/mercadopago'];
    }

    private function validSignature(string $header, string $requestId, string $paymentId, string $secret): bool
    {
        if ($secret === '' || $requestId === '') return false; $parts = [];
        foreach (explode(',', $header) as $part) { [$k,$v] = array_pad(explode('=', trim($part), 2), 2, ''); $parts[$k] = $v; }
        if (($parts['ts'] ?? '') === '' || ($parts['v1'] ?? '') === '') return false;
        $manifest = 'id:' . strtolower($paymentId) . ';request-id:' . $requestId . ';ts:' . $parts['ts'] . ';';
        return hash_equals(hash_hmac('sha256', $manifest, $secret), $parts['v1']);
    }

    private function mpState(string $providerStatus): string
    {
        return match ($providerStatus) {
            'approved' => 'approved',
            'rejected', 'cancelled', 'refunded', 'charged_back' => 'rejected',
            default => 'pending',
        };
    }

    private function decimal(float $cents): float { return round($cents / 100, 2); }

    private function sanitizeProviderPayload(array $payload): array
    {
        return array_intersect_key($payload, array_flip([
            'id',
            'status',
            'status_detail',
            'external_reference',
            'date_approved',
            'date_last_updated',
            'transaction_amount',
            'currency_id',
        ]));
    }

    private function transition(int $paymentId, string $to, array $extra = [], array $meta = []): array
    {
        return $this->db->transaction(fn(): array => $this->transitionWithinTransaction($paymentId, $to, $extra, $meta));
    }

    private function transitionWithinTransaction(int $paymentId, string $to, array $extra = [], array $meta = []): array
    {
        $payment = $this->payments->getById($paymentId); if (!$payment) throw new DomainException('PAYMENT_NOT_FOUND');
        $from = (string)$payment['state'];
        if (($payment['method'] === 'transfer' && $to === 'approved') || ($payment['method'] === 'mercadopago' && $to === 'verified')) throw new InvalidTransition($from . ' cannot transition to ' . $to);
        $state = PaymentStateMap::create()->transition($from, $to);
        $updated = $this->payments->updateState($paymentId, $state, $extra);
        $eventType = 'payment.' . $to;
        $this->payments->insertEvent($paymentId, $eventType, ['from'=>$from,'to'=>$to] + $meta);
        $this->audit?->append(['type'=>'user','id'=>$meta['actor_id'] ?? null], $eventType, 'payment:' . $paymentId, ['order_id'=>(int)$payment['order_id'],'from'=>$from,'to'=>$to] + $meta, $this->requestId);
        if ($this->notifications !== null && in_array($to, ['verified', 'approved', 'rejected'], true)) { // cancelled → none (spec N2)
            $orderRow = $this->orders->getById((int)$payment['order_id']); // same transaction
            if ($orderRow !== null) $this->notifications->enqueueForOrder($eventType, $orderRow);
        }
        if (in_array($state, ['verified','approved'], true)) $this->autoAcceptOrder((int)$payment['order_id']);
        return $updated;
    }
}
