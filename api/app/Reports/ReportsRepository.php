<?php declare(strict_types=1);
namespace VO\Reports;

use VO\Database\Connection;

/**
 * Read-only sales aggregates for the Ventas report and Dashboard V1 (vo-reports design).
 * Money is integer cents straight from SQL (COALESCE(SUM(...),0)); venta neta is derived
 * in ReportsService so the canonical formula lives in exactly one place. Every query
 * scopes rows via JOIN user_branches — out-of-scope branches naturally yield zero rows.
 */
final class ReportsRepository
{
    /** Pin A1: every status except rejected/cancelled/expired — live orders (incl. pending/change_proposed) count. */
    public const DEFAULT_STATUSES = ['pending', 'change_proposed', 'accepted', 'in_progress', 'ready', 'completed'];

    public function __construct(private Connection $db) {}

    /**
     * Per-day rows (date ASC) for a ReportsService::parseFilters shape.
     * Columns: date, orders, bruta, descuentos, delivery_cobrado, remuneracion (int cents).
     * Producto/categoría filters use EXISTS so order-level money is counted exactly once
     * regardless of how many lines match (a JOIN would fan out the sums).
     */
    public function summary(array $f, int $userId): array
    {
        $statuses = ($f['status'] ?? null) !== null ? [$f['status']] : self::DEFAULT_STATUSES;
        $where = 'o.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        $params = [$userId, $f['from'], $f['to'], ...$statuses];
        foreach ([['branchId', ' AND o.branch_id = ?'], ['fulfillment', ' AND o.fulfillment = ?'], ['paymentMethod', ' AND o.payment_method = ?']] as [$key, $clause]) {
            if (($f[$key] ?? null) !== null) { $where .= $clause; $params[] = $f[$key]; }
        }
        if (($f['productId'] ?? null) !== null) { $where .= ' AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.item_id = ?)'; $params[] = $f['productId']; }
        if (($f['categoryId'] ?? null) !== null) { $where .= ' AND EXISTS (SELECT 1 FROM order_items oi JOIN catalog_items ci ON ci.id = oi.item_id WHERE oi.order_id = o.id AND ci.category_id = ?)'; $params[] = $f['categoryId']; }
        $rows = $this->db->select(
            "SELECT DATE(o.created_at) AS date, COUNT(*) AS orders,
                COALESCE(SUM(o.gross_items_cents),0) AS bruta,
                COALESCE(SUM(o.item_promotions_cents + o.order_promotions_cents + o.coupon_discount_cents + o.payment_discount_cents),0) AS descuentos,
                COALESCE(SUM(o.delivery_fee_cents),0) AS delivery_cobrado,
                COALESCE(SUM(o.delivery_payout_cents),0) AS remuneracion
             FROM orders o
             JOIN user_branches ub ON ub.branch_id = o.branch_id AND ub.user_id = ?
             WHERE o.created_at >= ? AND o.created_at < ? + INTERVAL 1 DAY AND $where
             GROUP BY DATE(o.created_at) ORDER BY date",
            $params
        );
        foreach ($rows as &$r) $r = ['date' => (string)$r['date'], 'orders' => (int)$r['orders'], 'bruta' => (int)$r['bruta'],
            'descuentos' => (int)$r['descuentos'], 'delivery_cobrado' => (int)$r['delivery_cobrado'], 'remuneracion' => (int)$r['remuneracion']];
        return $rows;
    }

    /** Dashboard V1: the eleven pinned metrics (design), scoped to the user's branches. */
    public function dashboard(int $userId): array
    {
        $statuses = implode(',', array_fill(0, count(self::DEFAULT_STATUSES), '?'));
        $today = $this->db->select(
            "SELECT COALESCE(SUM(o.grand_total_cents),0) ventas, COUNT(*) pedidos
             FROM orders o JOIN user_branches ub ON ub.branch_id = o.branch_id AND ub.user_id = ?
             WHERE o.created_at >= CURDATE() AND o.created_at < CURDATE() + INTERVAL 1 DAY AND o.status IN ($statuses)",
            [$userId, ...self::DEFAULT_STATUSES]
        )[0];
        // Live status counts carry NO date filter (design pin).
        $live = $this->db->select(
            "SELECT COALESCE(SUM(o.status = 'pending'),0) nuevos, COALESCE(SUM(o.status = 'in_progress'),0) preparando,
                    COALESCE(SUM(o.status = 'ready'),0) listos, COALESCE(SUM(o.status = 'cancelled'),0) cancelados,
                    COALESCE(SUM(o.status = 'rejected'),0) rechazados
             FROM orders o JOIN user_branches ub ON ub.branch_id = o.branch_id AND ub.user_id = ?",
            [$userId]
        )[0];
        // uq_deliveries_order guarantees one delivery per order → no fan-out.
        $delivery = $this->db->select(
            "SELECT COALESCE(SUM(d.state IN ('pending','assigned')),0) buscando, COALESCE(SUM(d.state = 'picked_up'),0) camino
             FROM deliveries d JOIN orders o ON o.id = d.order_id
             JOIN user_branches ub ON ub.branch_id = o.branch_id AND ub.user_id = ?",
            [$userId]
        )[0];
        $stock = $this->db->select(
            "SELECT COUNT(*) n FROM branch_items bi JOIN user_branches ub ON ub.branch_id = bi.branch_id AND ub.user_id = ?
             WHERE bi.stock_mode = 'simple' AND bi.stock_quantity <= 0",
            [$userId]
        )[0]['n'];
        $pedidos = (int)$today['pedidos'];
        return [
            'ventas_hoy' => (int)$today['ventas'], 'pedidos_hoy' => $pedidos,
            'ticket_promedio' => $pedidos > 0 ? intdiv((int)$today['ventas'], $pedidos) : 0,
            'nuevos' => (int)$live['nuevos'], 'preparando' => (int)$live['preparando'], 'listos' => (int)$live['listos'],
            'buscando_delivery' => (int)$delivery['buscando'], 'en_camino' => (int)$delivery['camino'],
            'cancelados' => (int)$live['cancelados'], 'rechazados' => (int)$live['rechazados'],
            'stock_bajo' => (int)$stock,
        ];
    }
}
