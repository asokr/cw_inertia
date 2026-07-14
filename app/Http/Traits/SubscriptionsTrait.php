<?php

namespace App\Http\Traits;

use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Support\ToolLimits;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerSettings;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Oz\Feedbacks\FeedbacksClients as OzFeedbacksClients;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcCabinet;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationCabinets;

trait SubscriptionsTrait
{
    // Трейт синхронизирует подписки.
    private function syncLimits($subscriber_id, $limit_type)
    {
        $user = Subscribers::find($subscriber_id)->user;

        if (ToolLimits::bypassesFor($user)) {
            return false;
        }

        switch ($limit_type) {
            case 'feedbacks_clients':
                $clients = FeedbacksClients::where('subscriber_id', $subscriber_id)->get();
                $this->updateLimits('feedbacks_clients', $clients->count(), $subscriber_id);
                break;
            case 'oz_feedbacks_clients':
                $clients = OzFeedbacksClients::where('user_id', $user->id)->get();
                $this->updateLimits('oz_feedbacks_clients', $clients->count(), $subscriber_id);
                break;
            case 'price_calc_clients':
                $clients = PriceCalculationCabinets::where('user_id', $user->id)->get();
                $this->updateLimits('price_calc_clients', $clients->count(), $subscriber_id);
                break;
            case 'oz_price_calc_clients':
                $clients = OzPriceCalcCabinet::where('user_id', $user->id)->get();
                $this->updateLimits('oz_price_calc_clients', $clients->count(), $subscriber_id);
                break;
            case 'repricer_nmid':
                $cabinets = RepricerCabinets::where('user_id', $user->id)->get();
                $total_nmID = 0;
                foreach ($cabinets as $cabinet) {
                    $nmIDs = RepricerSettings::where('cabinet_id', $cabinet->id)->get();
                    $total_nmID += $nmIDs->count();
                }
                $this->updateLimits('repricer_nmid', $total_nmID, $subscriber_id);
                break;

            default:
                return false;
                break;
        }
    }

    // Возвращает кол-во уже использованых лимитов по лимитам плана
    private function getUsedLimits($subscriber_id, $limit_type)
    {
        $user = Subscribers::find($subscriber_id)->user;

        switch ($limit_type) {
            case 'feedbacks_clients':
                $clients = FeedbacksClients::where('subscriber_id', $subscriber_id)->get();
                return $clients->count();
                break;
            case 'oz_feedbacks_clients':
                $clients = OzFeedbacksClients::where('user_id', $user->id)->get();
                return $clients->count();
                break;
            case 'price_calc_clients':
                $clients = PriceCalculationCabinets::where('user_id', $user->id)->get();
                return $clients->count();
                break;
            case 'oz_price_calc_clients':
                $clients = OzPriceCalcCabinet::where('user_id', $user->id)->get();
                return $clients->count();
                break;
            case 'repricer_nmid':
                $cabinets = RepricerCabinets::where('user_id', $user->id)->get();
                $total_nmID = 0;
                foreach ($cabinets as $cabinet) {
                    $nmIDs = RepricerSettings::where('cabinet_id', $cabinet->id)->get();
                    $total_nmID += $nmIDs->count();
                }
                return $total_nmID;
                break;
            default:
                return false;
                break;
        }
    }

    private function updateLimits($limit_type, $num, $subscriber_id)
    {
        $userSubscription = SubscribersSubscriptions::where([
            'subscribers_id' => $subscriber_id,
        ])->first();

        if (isset($userSubscription->limits_plan[$limit_type])) {
            $plan = $userSubscription->getPlan();
            $new_limits = $userSubscription->limits_plan;
            $new_limit = $plan->limits_plan[$limit_type] - $num;
            if ($new_limit < 0) {
                $to_delete = abs($new_limit);
                $this->deleteOverLimits($subscriber_id, $limit_type, $to_delete);
                $new_limit = 0;
            }
            $new_limits[$limit_type] = $new_limit;
            $userSubscription->limits_plan = $new_limits;
            $userSubscription->save();
        }
    }

    private function deleteOverLimits($subscriber_id, $limit_type, $to_delete)
    {
        $user = Subscribers::find($subscriber_id)->user;

        switch ($limit_type) {
            case 'feedbacks_clients':
                $clients = FeedbacksClients::where('subscriber_id', $subscriber_id)->get();
                $i = 0;
                foreach ($clients as $client) {
                    $client->delete();
                    $i++;
                    if ($i >= $to_delete)
                        return;
                }
                break;
            case 'oz_feedbacks_clients':
                $clients = OzFeedbacksClients::where('subscriber_id', $user->id)->get();
                $i = 0;
                foreach ($clients as $client) {
                    $client->delete();
                    $i++;
                    if ($i >= $to_delete)
                        return;
                }
                break;
            case 'price_calc_clients':
                $clients = PriceCalculationCabinets::where('user_id', $user->id)->get();
                $i = 0;
                foreach ($clients as $client) {
                    $client->delete();
                    $i++;
                    if ($i >= $to_delete)
                        return;
                }
                break;
            case 'oz_price_calc_clients':
                $clients = OzPriceCalcCabinet::where('user_id', $user->id)->get();
                $i = 0;
                foreach ($clients as $client) {
                    $client->delete();
                    $i++;
                    if ($i >= $to_delete)
                        return;
                }
                break;
            case 'repricer_nmid':
                $cabinets = RepricerCabinets::where('user_id', $user->id)->get();
                $cabinets_ids = [];
                foreach ($cabinets as $cabinet) {
                    $cabinets_ids[] =  $cabinet->id;
                }
                $nmIds = RepricerSettings::whereIn('cabinet_id', $cabinets_ids)->orderBy('id', 'desc')->get();
                $i = 0;
                foreach ($nmIds as $nmId) {
                    $nmId->delete();
                    $i++;
                    if ($i >= $to_delete)
                        return;
                }
                break;

            default:
                return false;
                break;
        }
    }
}
