<?php declare(strict_types=1);
namespace VO\Notifications;

use Throwable; use VO\Database\Connection; use VO\Delivery\DeliveryRepository; use VO\Delivery\Pin; use VO\Settings\SettingsRepository;

/**
 * Customer notification service (specs N2–N4, N7, N8, N10). enqueueForOrder()
 * runs inside the triggering service's transaction: it gates on the business
 * flags (absent key = off), resolves recipients from the order contact
 * snapshot, and writes one pending row per enabled channel with ids-only
 * context. dispatchPendingLazy() sweeps pending rows, renders the Spanish
 * template at send time (the delivery PIN is recomputed via Pin::code and
 * never stored), and records typed outcomes; it NEVER throws.
 */
final class NotificationService
{
    private const MAX_ATTEMPTS = 3;

    /** event => [subject, body]; placeholders {number} and {status}. {pin} only in the WhatsApp delivery.assigned body. */
    private const TEMPLATES = [
        'order.created' => ['Pedido {number} recibido', 'Recibimos tu pedido {number}. Estado: {status}.'],
        'order.accepted' => ['Pedido {number} aceptado', 'Tu pedido {number} fue aceptado y ya lo estamos preparando. Estado: {status}.'],
        'order.change_approved' => ['Pedido {number}: cambio aprobado', 'Aprobamos el cambio de tu pedido {number}. Estado: {status}.'],
        'order.rejected' => ['Pedido {number} rechazado', 'Tu pedido {number} fue rechazado. Ante cualquier duda contactanos. Estado: {status}.'],
        'order.in_progress' => ['Pedido {number} en preparación', 'Tu pedido {number} ya se está preparando. Estado: {status}.'],
        'order.ready' => ['Pedido {number} listo para retiro', 'Tu pedido {number} está {status}. Pasá a retirarlo.'],
        'order.completed' => ['Pedido {number} completado', 'Tu pedido {number} fue {status}. ¡Gracias por elegirnos!'],
        'order.cancelled' => ['Pedido {number} cancelado', 'Tu pedido {number} fue {status}. Si abonaste, gestionamos la devolución.'],
        'payment.verified' => ['Pago del pedido {number} verificado', 'Verificamos el pago de tu pedido {number}. Estado: {status}.'],
        'payment.approved' => ['Pago del pedido {number} aprobado', 'El pago de tu pedido {number} fue aprobado. Estado: {status}.'],
        'payment.rejected' => ['Pago del pedido {number} rechazado', 'El pago de tu pedido {number} fue rechazado. Revisá el medio de pago. Estado: {status}.'],
        'delivery.assigned' => ['Pedido {number} en camino', 'Tu pedido {number} está {status}. Un repartidor lo está llevando a tu dirección.'],
        'delivery.picked_up' => ['Pedido {number} retirado', 'El repartidor retiró tu pedido {number}. Estado: {status}.'],
        'delivery.delivered' => ['Pedido {number} entregado', 'Tu pedido {number} fue {status}. ¡Que lo disfrutes!'],
    ];
    private const STATUS_LABELS = [
        'order.created' => 'recibido', 'order.accepted' => 'aceptado', 'order.change_approved' => 'cambio aprobado',
        'order.rejected' => 'rechazado', 'order.in_progress' => 'en preparación', 'order.ready' => 'listo para retiro',
        'order.completed' => 'completado', 'order.cancelled' => 'cancelado', 'payment.verified' => 'pago verificado',
        'payment.approved' => 'pago aprobado', 'payment.rejected' => 'pago rechazado', 'delivery.assigned' => 'en camino',
        'delivery.picked_up' => 'retirado', 'delivery.delivered' => 'entregado',
    ];
    private const WHATSAPP_PIN_BODY = 'Tu pedido {number} está {status}. PIN de entrega: {pin}. Mostralo al recibir.';

    /** Transports are nullable: null = channel unconfigured (typed failure per attempt, spec N4). */
    public function __construct(private Connection $db, private NotificationRepository $repo, private SettingsRepository $settings,
        private ?NotificationTransport $smtp = null, private ?NotificationTransport $whatsapp = null) {}

    /**
     * Gate + enqueue for one catalog event (spec N8): master flag, per-channel flag,
     * non-empty recipient from the order snapshot; context carries ids only (spec N1).
     * Joins the caller's transaction — a rollback removes the enqueued rows.
     */
    public function enqueueForOrder(string $event, array $orderRow, ?int $deliveryId = null): void
    {
        if (!isset(self::TEMPLATES[$event])) return;
        $businessId = (int)($orderRow['business_id'] ?? 0);
        if ($businessId === 0 || $this->settings->get($businessId, 'notifications_enabled') !== '1') return;
        $context = ['order_id' => (int)$orderRow['id'], 'order_number' => (string)$orderRow['number']];
        if ($deliveryId !== null) $context['delivery_id'] = $deliveryId;
        $email = trim((string)($orderRow['customer_email'] ?? ''));
        if ($email !== '' && $this->settings->get($businessId, 'notifications_email_enabled') === '1') {
            $this->repo->enqueue($businessId, $event, 'email', $email, null, $context);
        }
        $phone = trim((string)($orderRow['customer_phone'] ?? ''));
        if ($phone !== '' && $this->settings->get($businessId, 'notifications_whatsapp_enabled') === '1') {
            $this->repo->enqueue($businessId, $event, 'whatsapp', $phone, null, $context);
        }
    }

    /** Best-effort sweep (spec N3): returns how many rows were sent; never throws. */
    public function dispatchPendingLazy(?int $businessId = null, int $limit = 20): int
    {
        try { $rows = $this->repo->pending($limit, self::MAX_ATTEMPTS, $businessId); } catch (Throwable) { return 0; }
        $sent = 0;
        foreach ($rows as $row) {
            try { if ($this->dispatchRow($row)) $sent++; } catch (Throwable) { /* isolation per row: outcome already recorded */ }
        }
        return $sent;
    }

    /** Renders, sends, and records the outcome for one pending row. @param array<string,mixed> $row */
    private function dispatchRow(array $row): bool
    {
        $event = (string)$row['event'];
        $context = json_decode((string)($row['context_json'] ?? ''), true) ?: [];
        $placeholders = ['{number}' => (string)($context['order_number'] ?? ''), '{status}' => self::STATUS_LABELS[$event] ?? $event];
        [$subject, $body] = self::TEMPLATES[$event];
        if ($event === 'delivery.assigned' && (string)$row['channel'] === 'whatsapp') {
            $body = self::WHATSAPP_PIN_BODY; // PIN recomputed at send, never stored (spec N7)
            $placeholders['{pin}'] = $this->deliveryPin((int)$row['business_id'], (int)($context['delivery_id'] ?? 0), (int)($context['order_id'] ?? 0));
        }
        $subject = strtr($subject, $placeholders);
        $body = strtr($body, $placeholders);
        $transport = (string)$row['channel'] === 'email' ? $this->smtp : $this->whatsapp;
        try {
            $outcome = $transport !== null ? $transport->send((string)$row['recipient'], $subject, $body) : ['ok' => false, 'error' => 'canal no configurado'];
        } catch (Throwable $e) {
            $outcome = ['ok' => false, 'error' => 'transport exception: ' . $e->getMessage()]; // fakes/faulty transports must not escape
        }
        if ($outcome['ok'] === true) { $this->repo->markSent((int)$row['id']); return true; }
        $this->repo->markFailed((int)$row['id'], (int)$row['attempts'] + 1, (string)($outcome['error'] ?? 'error desconocido'));
        return false;
    }

    private function deliveryPin(int $businessId, int $deliveryId, int $orderId): string
    {
        if ($deliveryId === 0 || $orderId === 0) return '';
        return Pin::code((new DeliveryRepository($this->db))->ensurePinKey($businessId), $deliveryId, $orderId);
    }
}
