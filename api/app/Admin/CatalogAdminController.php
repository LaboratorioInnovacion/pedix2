<?php declare(strict_types=1);
namespace VO\Admin;

use InvalidArgumentException;
use PDO;
use VO\Audit\AuditService;
use VO\Auth\AuthSession;
use VO\Auth\BranchScope;
use VO\Auth\CsrfService;
use VO\Auth\PermissionGuard;
use VO\Catalog\CatalogRepository;
use VO\Catalog\CatalogService;
use VO\Support\Template;

final class CatalogAdminController
{
    private CatalogRepository $repo;
    private CatalogService $service;
    public function __construct(private PDO $pdo, private AuthSession $sessions, private CsrfService $csrf, private Template $tpl, private AuditService $audit, private ?string $requestId) { $this->repo = new CatalogRepository($pdo); $this->service = new CatalogService($this->repo, $pdo, $audit, $requestId); }

    public function handle(string $method, string $path): never
    {
        $user = $this->user();
        if ($user === null) $this->redirect('/admin/login');
        if (!(new PermissionGuard($this->pdo, (int)$user['id']))->requirePermission('products.manage')) $this->deny((int)$user['id'], 'products.manage', null);
        if ($method === 'GET' && $path === '/admin/catalogo') $this->dashboard($user);
        if ($path === '/admin/categorias') $method === 'POST' ? $this->saveCategory($user) : $this->categories($user);
        if ($path === '/admin/productos') $method === 'POST' ? $this->saveItem($user, null) : $this->items($user);
        if (preg_match('#^/admin/producto/(\d+)$#', $path, $m)) $method === 'POST' ? $this->saveItem($user, (int)$m[1]) : $this->itemForm($user, (int)$m[1]);
        if (preg_match('#^/admin/producto/(\d+)/archivar$#', $path, $m)) { if ($method !== 'POST') $this->notFound(); $this->archiveItem($user, (int)$m[1]); }
        if (preg_match('#^/admin/sucursales/(\d+)/catalogo$#', $path, $m)) $method === 'POST' ? $this->saveBranch($user, (int)$m[1]) : $this->branch($user, (int)$m[1]);
        $this->notFound();
    }

    private function dashboard(array $user): never { echo $this->tpl->render('catalog_dashboard', ['csrf'=>$this->token(), 'user'=>$user, 'categories'=>$this->repo->listAdminCategories(), 'items'=>$this->repo->listAdminItems()]); exit; }
    private function categories(array $user, array $errors = []): never { echo $this->tpl->render('catalog_categories', ['csrf'=>$this->token(), 'user'=>$user, 'errors'=>$errors, 'categories'=>$this->repo->listAdminCategories()]); exit; }
    private function items(array $user, array $errors = []): never { echo $this->tpl->render('catalog_items', ['csrf'=>$this->token(), 'user'=>$user, 'errors'=>$errors, 'items'=>$this->repo->listAdminItems(), 'categories'=>$this->repo->listAdminCategories(), 'groups'=>$this->groups()]); exit; }
    private function itemForm(array $user, int $id, array $errors = []): never { $item = $this->repo->findItemForEdit($id); if (!$item) $this->notFound(); echo $this->tpl->render('catalog_item_form', ['csrf'=>$this->token(), 'user'=>$user, 'errors'=>$errors, 'item'=>$item, 'categories'=>$this->repo->listAdminCategories(), 'groups'=>$this->groups(), 'linked'=>$this->linkedGroupIds($id), 'images'=>$this->repo->imagesForItem($id)]); exit; }

    private function saveCategory(array $user): never
    {
        $this->requireCsrf();
        try {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (($_POST['action'] ?? '') === 'archive' && $id > 0) $this->service->archiveCategory($id, $this->actor($user));
            elseif ($id > 0) $this->service->updateCategory($id, $_POST, $this->actor($user));
            else $this->service->createCategory($_POST, $this->actor($user));
            $this->redirect('/admin/categorias');
        }
        catch (InvalidArgumentException $e) { $this->categories($user, [$e->getMessage()]); }
    }

    private function saveItem(array $user, ?int $id): never
    {
        $this->requireCsrf();
        $variants = $this->variantsFromPost();
        try {
            $data = $_POST; $data['images'] = $this->imagesFromPost();
            $itemId = $id ? $this->service->updateItem($id, $data, $variants, $this->actor($user)) : $this->service->createItem($data, $variants, $this->actor($user));
            foreach ((array)($_POST['modifier_group_id'] ?? []) as $groupId) if ((int)$groupId > 0) $this->service->linkModifierGroup($itemId, (int)$groupId);
            $this->redirect('/admin/producto/' . $itemId);
        } catch (InvalidArgumentException $e) { $id ? $this->itemForm($user, $id, [$e->getMessage()]) : $this->items($user, [$e->getMessage()]); }
    }

    private function archiveItem(array $user, int $id): never { $this->requireCsrf(); $this->service->archiveItem($id, $this->actor($user)); $this->redirect('/admin/productos'); }

    private function branch(array $user, int $branchId, array $errors = []): never
    {
        $this->requireBranch((int)$user['id'], $branchId);
        echo $this->tpl->render('catalog_branch', ['csrf'=>$this->token(), 'user'=>$user, 'errors'=>$errors, 'branch'=>$this->branchRow($branchId), 'items'=>$this->repo->listAdminItems(), 'overrides'=>$this->overridesByItem($branchId)]); exit;
    }

    private function saveBranch(array $user, int $branchId): never
    {
        $this->requireBranch((int)$user['id'], $branchId); $this->requireCsrf();
        $available = (array)($_POST['branch_available'] ?? []); $prices = (array)($_POST['branch_price'] ?? []); $stock = (array)($_POST['stock_mode'] ?? []);
        foreach ($this->repo->listAdminItems() as $item) { $id = (int)$item['id']; $this->service->saveBranchCatalog($branchId, $id, ['is_available'=>isset($available[$id]), 'price_override_cents'=>$prices[$id] ?? null, 'stock_mode'=>$stock[$id] ?? 'none'], (string)$item['type'], $this->actor($user)); }
        $this->redirect('/admin/sucursales/' . $branchId . '/catalogo');
    }

    private function user(): ?array { $sid = $_SESSION['auth_sid'] ?? null; if (!is_string($sid) || $this->sessions->validate($sid) === null) return null; $s = $this->pdo->prepare('SELECT u.id,u.name,u.email,b.name business_name FROM users u JOIN businesses b ON b.id=u.business_id WHERE u.id=(SELECT user_id FROM auth_sessions WHERE sid_hash=? LIMIT 1) LIMIT 1'); $s->execute([AuthSession::hash($sid)]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
    private function deny(int $userId, string $permission, ?int $branchId): never { $this->audit->append(['type'=>'user','id'=>$userId], 'authz.denied', null, ['permission'=>$permission,'branch_id'=>$branchId], $this->requestId); http_response_code(403); echo $this->tpl->render('catalog_error', ['csrf'=>$this->token(), 'message'=>'No tenés permiso para administrar el catálogo.']); exit; }
    private function requireBranch(int $userId, int $branchId): void { if (!(new BranchScope($this->pdo))->requireBranch($userId, $branchId)) $this->deny($userId, 'products.manage', $branchId); }
    private function requireCsrf(): void { $t = $_POST['csrf'] ?? null; if (!$this->csrf->validate(is_string($t) ? $t : null) && !(is_string($t) && isset($_COOKIE['vo_csrf']) && hash_equals((string)$_COOKIE['vo_csrf'], $t))) { http_response_code(419); echo $this->tpl->render('catalog_error', ['csrf'=>$this->token(), 'message'=>'La solicitud venció. Volvé a intentar.']); exit; } }
    private function token(): string { $t = (isset($_COOKIE['vo_csrf']) && preg_match('/^[a-f0-9]{64}$/', (string)$_COOKIE['vo_csrf']) === 1) ? (string)$_COOKIE['vo_csrf'] : $this->csrf->issue(); $_SESSION['_csrf'] = $t; if (!headers_sent()) setcookie('vo_csrf', $t, ['path'=>'/admin','httponly'=>true,'samesite'=>'Lax']); return $t; }
    private function variantsFromPost(): array { $out = []; foreach ((array)($_POST['variant_name'] ?? []) as $i => $name) if (trim((string)$name) !== '') $out[] = ['name'=>$name, 'price_cents'=>((array)($_POST['variant_price_cents'] ?? []))[$i] ?? null, 'is_active'=>1]; return $out; }
    private function imagesFromPost(): array { $out = []; $ids = (array)($_POST['image_id'] ?? []); $sorts = (array)($_POST['image_sort'] ?? []); $alts = (array)($_POST['image_alt'] ?? []); foreach ((array)($_POST['image_filename'] ?? []) as $i => $filename) { $filename = trim((string)$filename); if ($filename === '') continue; $out[] = ['id'=>$ids[$i] ?? null, 'filename'=>$filename, 'sort'=>$sorts[$i] ?? 0, 'alt'=>$alts[$i] ?? null]; } return $out; }
    private function groups(): array { return $this->pdo->query('SELECT * FROM modifier_groups WHERE archived_at IS NULL ORDER BY sort_order,name')->fetchAll(PDO::FETCH_ASSOC); }
    private function linkedGroupIds(int $itemId): array { $s=$this->pdo->prepare('SELECT group_id FROM item_modifier_group WHERE item_id=?'); $s->execute([$itemId]); return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)); }
    private function branchRow(int $id): array { $s=$this->pdo->prepare('SELECT * FROM branches WHERE id=? LIMIT 1'); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: ['id'=>$id,'name'=>'Sucursal']; }
    private function overridesByItem(int $branchId): array { $out=[]; foreach ($this->repo->branchOverrides($branchId) as $r) $out[(int)$r['item_id']]=$r; return $out; }
    private function actor(array $user): array { return ['type'=>'user','id'=>(int)$user['id']]; }
    private function redirect(string $to): never { header('Location: ' . $to, true, 302); exit; }
    private function notFound(): never { http_response_code(404); echo 'No encontrado'; exit; }
}
