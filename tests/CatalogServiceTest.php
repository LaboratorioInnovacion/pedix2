<?php declare(strict_types=1);
namespace Tests;
use InvalidArgumentException; use VO\Catalog\CatalogRepository; use VO\Catalog\CatalogService; use VO\Database\MigrationRunner;

final class CatalogServiceTest extends TestCase
{
    public function testValidatesSlugVariantModifierAndServiceRulesWithRollback(): void
    {
        $scratch = $this->db(); if ($scratch === null) return;
        try {
            $service = $this->service($scratch);
            $cat = $service->createCategory(['name'=>'Food','slug'=>'food']);
            $this->assertThrows(InvalidArgumentException::class, fn () => $service->createCategory(['name'=>'Again','slug'=>'food']));
            $this->assertThrows(InvalidArgumentException::class, fn () => $service->createItem(['category_id'=>$cat,'type'=>'product','name'=>'P','slug'=>'p','requires_variant'=>1], []));
            $this->assertThrows(InvalidArgumentException::class, fn () => $service->saveModifierGroup(['name'=>'Sauces','selection'=>'multi','min_select'=>3,'max_select'=>1]));
            $this->assertThrows(InvalidArgumentException::class, fn () => $service->createItem(['type'=>'service','name'=>'Ship','slug'=>'ship','allows_delivery'=>1], []));
            $before = (int) $scratch->pdo->query('SELECT COUNT(*) FROM catalog_items')->fetchColumn();
            $this->assertThrows(InvalidArgumentException::class, fn () => $service->createItem(['type'=>'product','name'=>'Bad','slug'=>'bad','base_price_cents'=>-1], []));
            $this->assertSame($before, (int) $scratch->pdo->query('SELECT COUNT(*) FROM catalog_items')->fetchColumn());
        } finally { $scratch->drop(); }
    }

    public function testArchiveHidesRowsSearchEscapesLikeAndPriceChangeAuditKeepsOldNewCents(): void
    {
        $scratch = $this->db(); if ($scratch === null) return;
        try {
            $service = $this->service($scratch); $repo = new CatalogRepository($scratch->pdo);
            $cat = $service->createCategory(['name'=>'Meals','slug'=>'meals']);
            $item = $service->createItem(['category_id'=>$cat,'type'=>'product','name'=>'100% Burger','slug'=>'burger','base_price_cents'=>1200], []);
            $service->saveBranchCatalog(1, $item, ['is_available'=>1], 'product');
            $service->updateItem($item, ['category_id'=>$cat,'type'=>'product','name'=>'100% Burger','slug'=>'burger','base_price_cents'=>1500], []);
            $meta = json_decode((string) $scratch->pdo->query("SELECT metadata_json FROM audit_log WHERE action='catalog.price_changed' ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
            $this->assertSame(1200, $meta['old_cents']); $this->assertSame(1500, $meta['new_cents']);
            $this->assertSame(1, count($repo->findPublicItems(null, '100%')));
            $service->archiveItem($item);
            $this->assertSame(0, count($repo->findPublicItems(null, 'Burger')));
            $this->assertSame(1, (int) $scratch->pdo->query('SELECT COUNT(*) FROM catalog_items WHERE archived_at IS NOT NULL')->fetchColumn());
        } finally { $scratch->drop(); }
    }

    private function db(): ?ScratchDatabase { $s = ScratchDatabase::create('vo_cat_test_'); if ($s) { (new MigrationRunner($s->connection(), dirname(__DIR__) . '/api/database/migrations'))->run(); $s->pdo->exec("INSERT INTO businesses (name,slug) VALUES ('Demo','demo')"); $s->pdo->exec("INSERT INTO branches (business_id,name,is_active) VALUES (1,'Main',1)"); } return $s; }
    private function service(ScratchDatabase $s): CatalogService { return new CatalogService(new CatalogRepository($s->pdo), $s->pdo); }
}
