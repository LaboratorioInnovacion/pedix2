<?php declare(strict_types=1);
namespace VO\Installer;
use VO\Database\Connection;

final class InstallerSeeder
{
    public const PERMISSIONS = ['orders.view','orders.accept','orders.reject','orders.modify','orders.prepare','orders.mark_ready','orders.cancel','products.edit_price','products.change_availability','products.manage_stock','products.manage','deliveries.assign','deliveries.reassign','payments.verify_transfer','settings.manage','users.manage','reports.view'];

    public function __construct(private Connection $db) {}

    public function seed(array $input): array
    {
        return $this->db->transaction(function (Connection $db) use ($input): array {
            $businessId = $this->id('businesses', ['slug' => $input['business_slug'] ?? null], ['name' => $input['business_name'], 'timezone' => $input['timezone'] ?? 'UTC']);
            $branchId = $this->id('branches', ['business_id' => $businessId, 'name' => $input['branch_name']], ['address' => $input['branch_address'] ?? null, 'phone' => $input['branch_phone'] ?? null]);
            foreach (['timezone' => $input['timezone'] ?? 'UTC'] as $key => $value) $this->setting('business_settings', 'business_id', $businessId, $key, $value);
            $roleId = $this->id('roles', ['name' => 'owner'], ['label' => 'Owner', 'is_system' => 1]);
            foreach (self::PERMISSIONS as $key) {
                $permissionId = $this->id('permissions', ['permission_key' => $key], ['label' => $key]);
                $this->link('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
            $hash = password_hash($input['admin_password'], PASSWORD_DEFAULT);
            $userId = $this->id('users', ['email' => $input['admin_email']], ['business_id' => $businessId, 'name' => $input['admin_name'], 'password_hash' => $hash]);
            $this->link('user_roles', ['user_id' => $userId, 'role_id' => $roleId]);
            $this->link('user_branches', ['user_id' => $userId, 'branch_id' => $branchId]);
            $this->audit($businessId, ['business_id' => $businessId, 'branch_id' => $branchId, 'user_id' => $userId]);
            return compact('businessId', 'branchId', 'roleId', 'userId');
        });
    }

    private function id(string $table, array $keys, array $values): int
    {
        $where = implode(' AND ', array_map(static fn ($k) => "$k <=> ?", array_keys($keys)));
        $found = $this->db->select("SELECT id FROM $table WHERE $where LIMIT 1", array_values($keys));
        if ($found) return (int) $found[0]['id'];
        $data = $keys + $values; $cols = array_keys($data);
        $this->db->execute("INSERT INTO $table (" . implode(',', $cols) . ") VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")", array_values($data));
        return (int) $this->db->select('SELECT LAST_INSERT_ID() id')[0]['id'];
    }
    private function link(string $table, array $keys): void
    {
        $where = implode(' AND ', array_map(static fn ($k) => "$k = ?", array_keys($keys)));
        if (!$this->db->select("SELECT 1 FROM $table WHERE $where LIMIT 1", array_values($keys))) {
            $this->db->execute("INSERT INTO $table (" . implode(',', array_keys($keys)) . ") VALUES (" . rtrim(str_repeat('?,', count($keys)), ',') . ")", array_values($keys));
        }
    }
    private function setting(string $table, string $fk, int $id, string $key, string $value): void { $this->id($table, [$fk => $id, 'setting_key' => $key], ['setting_value' => $value]); }
    private function audit(int $businessId, array $meta): void { if (!$this->db->select("SELECT 1 FROM audit_log WHERE action = 'installer.completed' LIMIT 1")) $this->db->execute('INSERT INTO audit_log (actor_type, action, entity_type, entity_id, metadata_json, request_id) VALUES (?, ?, ?, ?, ?, ?)', ['installer', 'installer.completed', 'business', $businessId, json_encode($meta, JSON_THROW_ON_ERROR), bin2hex(random_bytes(16))]); }
}
