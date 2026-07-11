<?php

use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    private const OZ_FEEDBACKS_PERMISSION = 'subscriber oz feedbacks';

    private const OZ_PRICE_CALC_PERMISSION = 'subscriber oz price calc';

    public function up(): void
    {
        Permission::updateOrCreate([
            'guard_name' => 'web',
            'name' => self::OZ_PRICE_CALC_PERMISSION,
        ]);

        SubscribersPlans::query()->each(function (SubscribersPlans $plan): void {
            $permissions = $plan->permissions ?? [];

            if (! is_array($permissions)) {
                return;
            }

            $hasOzFeedbacks = in_array(self::OZ_FEEDBACKS_PERMISSION, $permissions, true);
            $hasOzPriceCalc = in_array(self::OZ_PRICE_CALC_PERMISSION, $permissions, true);

            if ($hasOzFeedbacks && ! $hasOzPriceCalc) {
                $permissions[] = self::OZ_PRICE_CALC_PERMISSION;
                $plan->permissions = array_values($permissions);
            }

            $limitsPlan = $plan->limits_plan ?? [];

            if (is_array($limitsPlan)
                && isset($limitsPlan['oz_feedbacks_clients'])
                && ! isset($limitsPlan['oz_price_calc_clients'])) {
                $limitsPlan['oz_price_calc_clients'] = $limitsPlan['oz_feedbacks_clients'];
                $plan->limits_plan = $limitsPlan;
            }

            if ($plan->isDirty()) {
                $plan->save();
            }
        });

        SubscribersSubscriptions::query()->each(function (SubscribersSubscriptions $subscription): void {
            $limitsPlan = $subscription->limits_plan ?? [];

            if (! is_array($limitsPlan)) {
                return;
            }

            if (isset($limitsPlan['oz_feedbacks_clients']) && ! isset($limitsPlan['oz_price_calc_clients'])) {
                $limitsPlan['oz_price_calc_clients'] = $limitsPlan['oz_feedbacks_clients'];
                $subscription->limits_plan = $limitsPlan;
                $subscription->save();
            }
        });

        User::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', self::OZ_FEEDBACKS_PERMISSION))
            ->each(function (User $user): void {
                if (! $user->hasPermissionTo(self::OZ_PRICE_CALC_PERMISSION)) {
                    $user->givePermissionTo(self::OZ_PRICE_CALC_PERMISSION);
                }
            });
    }

    public function down(): void
    {
        // Permissions and limits are not rolled back to avoid removing user access unexpectedly.
    }
};