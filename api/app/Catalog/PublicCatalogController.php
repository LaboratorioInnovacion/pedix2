<?php declare(strict_types=1);
namespace VO\Catalog;

use PDO;
use VO\Support\Template;

final class PublicCatalogController
{
    private CatalogRepository $repo;
    private CatalogService $service;

    public function __construct(PDO $pdo, private Template $tpl)
    {
        $this->repo = new CatalogRepository($pdo);
        $this->service = new CatalogService($this->repo, $pdo, null);
    }

    public function home(): void { $this->render('home', ['title'=>'Catálogo', 'items'=>$this->cards($this->repo->findPublicItems()), 'categories'=>$this->repo->activeCategories()]); }

    public function category(string $slug): void
    {
        $category = $this->repo->findActiveCategoryBySlug($slug);
        if (!$category) $this->notFound();
        $this->render('category', ['title'=>(string)$category['name'], 'category'=>$category, 'categories'=>$this->children((int)$category['id']), 'items'=>$this->cards($this->repo->findPublicItems($slug))]);
    }

    public function product(string $slug): void
    {
        $item = $this->repo->findPublicItemBySlug($slug);
        if (!$item) $this->notFound();
        $branchItem = $this->repo->publicBranchItem((int)$item['id']);
        $variants = array_map(fn(array $v): array => $v + ['display_price'=>$this->money($this->service->resolveDisplayPrice($item, $branchItem, $v, $this->repo->publicBranchVariant((int)$v['id'])))], $this->repo->activeVariants((int)$item['id']));
        $groups = [];
        foreach ($this->repo->modifierGroupsForItem((int)$item['id']) as $g) {
            if ((int)($g['is_active'] ?? 1) !== 1) continue;
            $g['modifiers'] = array_map(fn(array $m): array => $m + ['display_price'=>$this->delta((int)$m['price_delta_cents'])], $this->repo->activeModifiers((int)$g['id']));
            $groups[] = $g;
        }
        $item['display_price'] = $this->money($this->service->resolveDisplayPrice($item, $branchItem));
        $this->render('product', ['title'=>(string)$item['name'], 'item'=>$item, 'variants'=>$variants, 'groups'=>$groups]);
    }

    public function search(string $q): void { $this->render('search', ['title'=>'Buscar', 'q'=>$q, 'items'=>$this->cards($this->repo->findPublicItems(null, $q))]); }

    public function notFound(): void { http_response_code(404); $this->render('404', ['title'=>'No encontrado']); }

    private function cards(array $items): array { return array_map(function(array $item): array { $item['display_price'] = $this->money($this->service->resolveDisplayPrice($item, $this->repo->publicBranchItem((int)$item['id']))); return $item; }, $items); }
    private function children(int $parentId): array { return array_values(array_filter($this->repo->activeCategories(), fn(array $c): bool => (int)($c['parent_id'] ?? 0) === $parentId)); }
    private function money(?int $cents): string { return $cents === null ? 'Consultar' : '$ ' . number_format($cents / 100, 2, ',', '.'); }
    private function delta(int $cents): string { return $cents === 0 ? 'Sin cargo' : '+ ' . $this->money($cents); }
    private function render(string $view, array $data): void { echo $this->tpl->render($view, $data); }
}
