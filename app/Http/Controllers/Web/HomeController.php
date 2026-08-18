<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Subscribers\SubscribersPlans;
use App\Support\HomeRedirect;
use App\Support\PlanLimitPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Home/Index', [
            'authenticated' => (bool) $user,
            'userName' => $user?->name,
            'homeUrl' => HomeRedirect::forUser($user),
            'cabinetLabel' => HomeRedirect::cabinetLabel($user),
            'isSubscriber' => (bool) $user?->hasRole('Подписчик'),
            'pricingPlans' => $this->pricingPlans(),
        ]);
    }

    /**
     * Карточки тарифов на главной: кабинеты и кредиты.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pricingPlans(): array
    {
        $columns = ['id', 'name', 'description', 'price', 'duration', 'limits_plan'];
        if (Schema::hasColumn('subscribers_plans', 'credits_per_period')) {
            $columns[] = 'credits_per_period';
        }

        $plans = SubscribersPlans::query()
            ->select($columns)
            ->where(['status' => 1, 'hidden' => 0])
            ->orderBy('price')
            ->get();

        // Featured public set: Базовый / Оптимальный / Профи if present; else top 3 by price.
        $featuredNames = ['Базовый', 'Оптимальный', 'Профи'];
        $featured = $plans->filter(fn ($p) => in_array($p->name, $featuredNames, true))->values();
        if ($featured->count() < 1) {
            $featured = $plans->take(3)->values();
        }

        $middleIndex = (int) floor(max(0, $featured->count() - 1) / 2);

        return $featured
            ->map(function (SubscribersPlans $plan, int $index) use ($middleIndex) {
                $displayLimits = PlanLimitPresenter::displayTariffEntries(
                    is_array($plan->limits_plan) ? $plan->limits_plan : [],
                    (int) ($plan->credits_per_period ?? 0),
                );
                $lines = PlanLimitPresenter::displayLines(
                    is_array($plan->limits_plan) ? $plan->limits_plan : [],
                );

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'subtitle' => strip_tags((string) ($plan->description ?? '')),
                    'price' => number_format((float) $plan->price, 0, '', ' ').' ₽',
                    'period' => '/ '.$plan->duration.' дней',
                    'popular' => $index === $middleIndex,
                    'stars' => min(3, $index + 1),
                    'limits' => $lines['plan'],
                    'monthly' => [],
                    'display_limits' => $displayLimits,
                ];
            })
            ->values()
            ->all();
    }
}