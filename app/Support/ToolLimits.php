<?php

namespace App\Support;

use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;

class ToolLimits
{
    public const UNLIMITED_VALUE = 999999;

    public static function bypassesFor(?User $user): bool
    {
        return $user !== null && HomeRedirect::isAdmin($user);
    }

    public static function monthLimitValue(?User $user, ?SubscribersSubscriptions $subscription, string $key): int
    {
        if (self::bypassesFor($user)) {
            return self::UNLIMITED_VALUE;
        }

        if (! $subscription) {
            return 0;
        }

        $value = $subscription->getMonthLimit($key);

        return $value === false ? 0 : (int) $value;
    }

    public static function planLimitValue(?User $user, ?SubscribersSubscriptions $subscription, string $key): ?int
    {
        if (self::bypassesFor($user)) {
            return self::UNLIMITED_VALUE;
        }

        if (! $subscription || ! isset($subscription->limits_plan[$key])) {
            return null;
        }

        return (int) $subscription->limits_plan[$key];
    }

    public static function canUsePlanLimit(?User $user, array $limits, string $key): bool
    {
        if (self::bypassesFor($user) || ! array_key_exists($key, $limits)) {
            return true;
        }

        return (int) $limits[$key] > 0;
    }

    /**
     * @return array<string, int>|null Updated limits when subscription row should be saved.
     */
    public static function applyPlanLimitConsumption(?User $user, array $limits, string $key): ?array
    {
        if (self::bypassesFor($user) || ! array_key_exists($key, $limits)) {
            return null;
        }

        $limits[$key] = (int) $limits[$key] - 1;

        return $limits;
    }

    /**
     * @return array<string, int>
     */
    public static function unlimitedAiLimits(bool $includeVideo = false): array
    {
        $limits = [
            'AI_TEXT_QUERY' => self::UNLIMITED_VALUE,
            'AI_IMAGE_QUERY' => self::UNLIMITED_VALUE,
            'AI_TEXT_QUERY_EXTRA' => 0,
            'AI_IMAGE_QUERY_EXTRA' => 0,
            'AI_TEXT_QUERY_TOTAL' => self::UNLIMITED_VALUE,
            'AI_IMAGE_QUERY_TOTAL' => self::UNLIMITED_VALUE,
        ];

        if ($includeVideo) {
            $limits['AI_VIDEO_QUERY'] = self::UNLIMITED_VALUE;
            $limits['AI_VIDEO_QUERY_EXTRA'] = 0;
            $limits['AI_VIDEO_QUERY_TOTAL'] = self::UNLIMITED_VALUE;
        }

        return $limits;
    }
}