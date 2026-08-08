<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    private const LEGACY_KEYS = ['oz_price_calc_clients', 'oz_feedbacks_clients'];

    public function up(): void
    {
        $this->normalizeLimitsJsonTable('subscribers_plans');
        $this->normalizeLimitsJsonTable('subscribers_subscriptions');

        Schema::dropIfExists('oz_feedbacks_clients');

        $permission = Permission::query()
            ->where('name', 'subscriber oz feedbacks')
            ->where('guard_name', 'web')
            ->first();

        if ($permission) {
            DB::table('role_has_permissions')->where('permission_id', $permission->id)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permission->id)->delete();
            $permission->delete();
        }
    }

    public function down(): void
    {
        // Module and legacy keys removed intentionally; no rollback.
    }

    private function normalizeLimitsJsonTable(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'limits_plan')) {
            return;
        }

        $rows = DB::table($table)->select(['id', 'limits_plan'])->get();

        foreach ($rows as $row) {
            $limits = $this->decodeLimits($row->limits_plan);
            if ($limits === null) {
                continue;
            }

            $changed = false;
            $legacyValues = [];

            foreach (self::LEGACY_KEYS as $legacyKey) {
                if (array_key_exists($legacyKey, $limits)) {
                    $legacyValues[] = (int) $limits[$legacyKey];
                    unset($limits[$legacyKey]);
                    $changed = true;
                }
            }

            if (! array_key_exists('oz_cabinets', $limits) && $legacyValues !== []) {
                $limits['oz_cabinets'] = max($legacyValues);
                $changed = true;
            }

            if (! $changed) {
                continue;
            }

            DB::table($table)->where('id', $row->id)->update([
                'limits_plan' => json_encode($limits, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeLimits(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
};
