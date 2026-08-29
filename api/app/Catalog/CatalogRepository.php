<?php declare(strict_types=1);
namespace VO\Catalog;

use InvalidArgumentException; use PDO;

final class CatalogRepository
{
    private const TABLES = ['categories','catalog_items','item_variants','modifier_groups','modifiers','branch_items','branch_variants','item_images'];
    public function __construct(private PDO $pdo) {}

    public function listAdminCategories(bool $includeArchived = false): array { return $this->all('categories', $includeArchived, 'sort_order,name'); }
    public function listAdminItems(bool $includeArchived = false): array { return $this->all('catalog_items', $includeArchived, 'name'); }
    public function findItemForEdit(int $id): ?array { return $this->one('SELECT * FROM catalog_items WHERE id=?', [$id]); }
    public function activeVariants(int $itemId): array { return $this->many('SELECT * FROM item_variants WHERE item_id=? AND is_active=1 AND archived_at IS NULL ORDER BY sort_order,name', [$itemId]); }
    public function modifierGroupsForItem(int $itemId): array { return $this->many('SELECT g.* FROM modifier_groups g JOIN item_modifier_group l ON l.group_id=g.id WHERE l.item_id=? AND g.archived_at IS NULL ORDER BY l.sort_order,g.sort_order,g.name', [$itemId]); }
    public function activeCategories(): array { return $this->many('SELECT * FROM categories WHERE is_active=1 AND archived_at IS NULL ORDER BY sort_order,name'); }
    public function findActiveCategoryBySlug(string $slug): ?array { return $this->one('SELECT * FROM categories WHERE slug=? AND is_active=1 AND archived_at IS NULL LIMIT 1', [$slug]); }
    public function activeModifiers(int $groupId): array { return $this->many('SELECT * FROM modifiers WHERE group_id=? AND is_active=1 AND archived_at IS NULL ORDER BY sort_order,name', [$groupId]); }
    public function branchOverrides(int $branchId): array { return $this->many('SELECT * FROM branch_items WHERE branch_id=?', [$branchId]); }
    public function slugExists(string $table, string $slug, ?int $exceptId = null): bool { $this->guard($table); $sql = "SELECT 1 FROM $table WHERE slug=?" . ($exceptId ? ' AND id<>?' : '') . ' LIMIT 1'; return (bool) $this->one($sql, $exceptId ? [$slug,$exceptId] : [$slug]); }
    public function archive(string $table, int $id): void { $this->guard($table); $this->exec("UPDATE $table SET archived_at=CURRENT_TIMESTAMP WHERE id=? AND archived_at IS NULL", [$id]); }
    public function categoryIdsWithDescendants(string $slug): array
    {
        return array_map('intval', array_column($this->many('WITH RECURSIVE cat_tree AS (SELECT id FROM categories WHERE slug=? AND is_active=1 AND archived_at IS NULL UNION ALL SELECT c.id FROM categories c JOIN cat_tree t ON c.parent_id=t.id WHERE c.is_active=1 AND c.archived_at IS NULL) SELECT id FROM cat_tree', [$slug]), 'id'));
    }
    public function findPublicItems(?string $categorySlug = null, ?string $q = null): array
    {
        $where = ['i.is_active=1','i.archived_at IS NULL','EXISTS (SELECT 1 FROM branch_items bi JOIN branches b ON b.id=bi.branch_id WHERE bi.item_id=i.id AND bi.is_available=1 AND b.is_active=1)']; $args = [];
        if ($categorySlug) { $ids = $this->categoryIdsWithDescendants($categorySlug); if (!$ids) return []; $where[] = 'i.category_id IN (' . rtrim(str_repeat('?,', count($ids)), ',') . ')'; $args = array_merge($args, $ids); }
        if ($q !== null && $q !== '') { $where[] = "(i.name LIKE ? ESCAPE '\\\\' OR i.description LIKE ? ESCAPE '\\\\')"; $like = '%' . $this->escapeLike($q) . '%'; array_push($args, $like, $like); }
        return $this->many('SELECT i.* FROM catalog_items i WHERE ' . implode(' AND ', $where) . ' ORDER BY i.name', $args);
    }
    public function findPublicItemBySlug(string $slug): ?array { return $this->one('SELECT * FROM catalog_items i WHERE i.slug=? AND i.is_active=1 AND i.archived_at IS NULL AND EXISTS (SELECT 1 FROM branch_items bi JOIN branches b ON b.id=bi.branch_id WHERE bi.item_id=i.id AND bi.is_available=1 AND b.is_active=1)', [$slug]); }
    public function publicBranchItem(int $itemId): ?array { return $this->one('SELECT bi.* FROM branch_items bi JOIN branches b ON b.id=bi.branch_id WHERE bi.item_id=? AND bi.is_available=1 AND b.is_active=1 ORDER BY bi.branch_id LIMIT 1', [$itemId]); }
    public function publicBranchVariant(int $variantId): ?array { return $this->one('SELECT bv.* FROM branch_variants bv JOIN branches b ON b.id=bv.branch_id WHERE bv.variant_id=? AND bv.is_available=1 AND b.is_active=1 ORDER BY bv.branch_id LIMIT 1', [$variantId]); }
    public function saveCategory(array $d): int { return $this->save('categories', ['parent_id','name','slug','description','sort_order','is_active'], $d); }
    public function saveItem(array $d): int { return $this->save('catalog_items', ['category_id','type','name','slug','description','base_price_cents','requires_variant','allows_pickup','allows_delivery','is_active'], $d); }
    public function saveVariant(int $itemId, array $d): int { $d['item_id'] = $itemId; return $this->save('item_variants', ['item_id','name','sku','price_cents','is_active','sort_order'], $d); }
    public function saveModifierGroup(array $d): int { return $this->save('modifier_groups', ['name','is_required','selection','min_select','max_select','sort_order','is_active'], $d); }
    public function linkModifierGroup(int $itemId, int $groupId, int $sort = 0): void { $this->exec('INSERT INTO item_modifier_group (item_id,group_id,sort_order) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order)', [$itemId,$groupId,$sort]); }
    public function saveBranchCatalog(int $branchId, int $itemId, bool $available, ?int $price, string $stockMode): void { $this->exec('INSERT INTO branch_items (branch_id,item_id,is_available,price_override_cents,stock_mode) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE is_available=VALUES(is_available),price_override_cents=VALUES(price_override_cents),stock_mode=VALUES(stock_mode)', [$branchId,$itemId,$available ? 1 : 0,$price,$stockMode]); }
    public function findBranchItem(int $branchId, int $itemId): ?array { return $this->one('SELECT * FROM branch_items WHERE branch_id=? AND item_id=? LIMIT 1', [$branchId,$itemId]); }
    public function findVariant(int $id): ?array { return $this->one('SELECT * FROM item_variants WHERE id=? LIMIT 1', [$id]); }
    public function imagesForItem(int $itemId): array { return $this->many('SELECT * FROM item_images WHERE item_id=? AND is_active=1 ORDER BY sort_order,id', [$itemId]); }
    public function allImagesForItem(int $itemId): array { return $this->many('SELECT * FROM item_images WHERE item_id=? ORDER BY sort_order,id', [$itemId]); }
    public function saveItemImage(array $d): int { return $this->save('item_images', ['item_id','filename','alt_text','sort_order','is_active'], $d); }
    public function deactivateImagesExcept(int $itemId, array $keepIds): void { $ids = array_values(array_unique(array_map('intval', $keepIds))); $sql = 'UPDATE item_images SET is_active=0 WHERE item_id=? AND is_active=1' . ($ids ? ' AND id NOT IN (' . rtrim(str_repeat('?,', count($ids)), ',') . ')' : ''); $this->exec($sql, [$itemId, ...$ids]); }

    private function all(string $table, bool $archived, string $order): array { $this->guard($table); return $this->many("SELECT * FROM $table" . ($archived ? '' : ' WHERE archived_at IS NULL') . " ORDER BY $order"); }
    private function save(string $table, array $cols, array $d): int { $this->guard($table); $id = isset($d['id']) ? (int) $d['id'] : 0; $vals = array_map(fn($c) => $d[$c] ?? null, $cols); if ($id) { $set = implode(',', array_map(fn($c) => "$c=?", $cols)); $this->exec("UPDATE $table SET $set WHERE id=?", [...$vals,$id]); return $id; } $this->exec("INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')', $vals); return (int) $this->pdo->lastInsertId(); }
    private function many(string $sql, array $p = []): array { $s = $this->pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function one(string $sql, array $p = []): ?array { $r = $this->many($sql, $p); return $r[0] ?? null; }
    private function exec(string $sql, array $p = []): void { $s = $this->pdo->prepare($sql); $s->execute($p); }
    private function guard(string $table): void { if (!in_array($table, self::TABLES, true)) throw new InvalidArgumentException('Invalid catalog table.'); }
    private function escapeLike(string $value): string { return strtr($value, ['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']); }
}
