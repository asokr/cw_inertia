<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Subscribers\SubscribersPlans;
use App\Support\HomeRedirect;
use App\Support\PlanLimitPresenter;
use Illuminate\Http\Request;
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
     * Public pricing cards from DB (unified WB cabinets + labels from extra_limits).
     *
     * @return array<int, array<string, mixed>>
     */
    private function pricingPlans(): array
    {
        $plans = SubscribersPlans::query()
            ->select(['id', 'name', 'description', 'price', 'duration', 'limits_plan', 'limits_month'])
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
                $lines = PlanLimitPresenter::displayLines(
                    is_array($plan->limits_plan) ? $plan->limits_plan : [],
                    is_array($plan->limits_month) ? $plan->limits_month : [],
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
                    'monthly' => $lines['month'],
                    'display_limits' => PlanLimitPresenter::displayEntries(
                        is_array($plan->limits_plan) ? $plan->limits_plan : [],
                        is_array($plan->limits_month) ? $plan->limits_month : [],
                    ),
                ];
            })
            ->values()
            ->all();
    }
}