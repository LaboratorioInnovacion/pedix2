<?php declare(strict_types=1);
namespace VO\Reports;

use DateTimeImmutable; use VO\Database\Connection;

/**
 * Ventas report orchestration: filter parsing/validation (invalid values are silently
 * ignored — the read page never hard-fails, same policy as the notifications log),
 * date presets, scoped branch list for the selector, and the canonical integer-cents
 * money math (neta + totals rollup) consumed by the page template and the CSV stream.
 */
final class ReportsService
{
    public const STATUSES = ['pending', 'change_proposed', 'accepted', 'in_progress', 'ready', 'completed', 'rejected', 'cancelled', 'expired'];
    public const FULFILLMENTS = ['pickup', 'delivery'];
    public const PRESETS = ['hoy', '7d', '30d'];
    public const METRIC_LABELS = ['orders' => 'Pedidos', 'bruta' => 'Venta bruta', 'descuentos' => 'Descuentos',
        'delivery_cobrado' => 'Delivery cobrado', 'remuneracion' => 'Remuneración delivery', 'neta' => 'Venta neta operativa'];
    private const MAX_SPAN_DAYS = 366;

    public function __construct(private ReportsRepository $reports, private Connection $db) {}

    /**
     * Filters DTO (design shape). Query keys: preset, from, to, branch_id, producto,
     * categoria, medio, modalidad, estado. preset=custom with a valid from/to range is
     * honored; anything invalid (bad dates, reversed, span > 366d) falls back to the
     * 30d default instead of erroring (spec R2).
     */
    public function parseFilters(array $get, int $userId): array
    {
        $from = $this->date($get['from'] ?? null);
        $to = $this->date($get['to'] ?? null);
        $preset = (string)($get['preset'] ?? '');
        if ($preset === 'custom' && $from !== null && $to !== null && $from <= $to && $this->spanDays($from, $to) <= self::MAX_SPAN_DAYS) {
            return $this->filters('custom', $from, $to, $get);
        }
        $preset = in_array($preset, self::PRESETS, true) ? $preset : '30d';
        $to = date('Y-m-d');
        $from = date('Y-m-d', match ($preset) { 'hoy' => strtotime($to), '7d' => strtotime($to . ' -6 days'), default => strtotime($to . ' -29 days') });
        return $this->filters($preset, $from, $to, $get);
    }

    /**
     * Full report for the page/CSV: daily rows (neta decorated per row) + totals.
     * Branch scope is enforced by the repository's user_branches JOIN: selecting an
     * out-of-scope branch yields zero rows, never another branch's data (spec R7) —
     * $userId is kept in the signature for that contract even though no pre-check applies.
     */
    public function report(array $filters, int $userId): array
    {
        $rows = $this->reports->summary($filters, $userId);
        foreach ($rows as &$r) $r['neta'] = $this->neta((int)$r['bruta'], (int)$r['descuentos'], (int)$r['delivery_cobrado'], (int)$r['remuneracion']);
        return ['rows' => $rows, 'totals' => $this->totalsFor($rows)];
    }

    /** Canonical formula (spec R4): neta = bruta − descuentos + delivery cobrado − remuneración delivery. */
    public function neta(int $bruta, int $descuentos, int $deliveryCobrado, int $remuneracion): int
    {
        return $bruta - $descuentos + $deliveryCobrado - $remuneracion;
    }

    /** Totals rollup over daily rows; an empty dataset yields an explicit zero row, never null (spec R5). */
    public function totalsFor(array $rows): array
    {
        $t = ['orders' => 0, 'bruta' => 0, 'descuentos' => 0, 'delivery_cobrado' => 0, 'remuneracion' => 0, 'neta' => 0];
        foreach ($rows as $r) foreach (['orders', 'bruta', 'descuentos', 'delivery_cobrado', 'remuneracion'] as $k) $t[$k] += (int)$r[$k];
        $t['neta'] = $this->neta($t['bruta'], $t['descuentos'], $t['delivery_cobrado'], $t['remuneracion']);
        return $t;
    }

    /** Scoped branch list for the sucursal selector (same shape as the other admin pages). */
    public function branchesFor(int $userId): array
    {
        return $this->db->select('SELECT b.id, b.name FROM branches b JOIN user_branches ub ON ub.branch_id = b.id WHERE ub.user_id = ? ORDER BY b.name', [$userId]);
    }

    /** Integer-cents ARS display: "$ 1.234,56" / "-$ 12,34" — no float ever touches the math. */
    public static function money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : ''; $abs = abs($cents);
        return $sign . '$ ' . number_format(intdiv($abs, 100), 0, ',', '.') . ',' . str_pad((string)($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private function filters(string $preset, string $from, string $to, array $get): array
    {
        return [
            'preset' => $preset, 'from' => $from, 'to' => $to,
            'branchId' => $this->positiveInt($get['branch_id'] ?? null),
            'productId' => $this->positiveInt($get['producto'] ?? null),
            'categoryId' => $this->positiveInt($get['categoria'] ?? null),
            'paymentMethod' => $this->paymentMethod($get['medio'] ?? null),
            'fulfillment' => in_array($get['modalidad'] ?? '', self::FULFILLMENTS, true) ? (string)$get['modalidad'] : null,
            'status' => in_array($get['estado'] ?? '', self::STATUSES, true) ? (string)$get['estado'] : null,
        ];
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) return null;
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y) ? $value : null;
    }

    private function spanDays(string $from, string $to): int
    {
        return (new DateTimeImmutable($to))->diff(new DateTimeImmutable($from))->days;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) && (int)$value > 0 ? (int)$value : null;
    }

    private function paymentMethod(mixed $value): ?string
    {
        $v = is_string($value) ? trim($value) : '';
        return $v !== '' && strlen($v) <= 64 ? $v : null;
    }
}
