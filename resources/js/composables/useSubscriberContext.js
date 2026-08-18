import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

export function useSubscriberContext() {
    const page = usePage();

    const subscriber = computed(() => page.props.subscriber ?? null);
    const balance = computed(() => subscriber.value?.balance ?? 0);
    const promoBanner = computed(() => subscriber.value?.promo_banner ?? null);
    const subscription = computed(() => subscriber.value?.subscription ?? null);
    const daysIndicator = computed(() => subscriber.value?.days_indicator ?? null);
    const hasSeenTour = computed(() => subscriber.value?.has_seen_tour ?? false);
    const credits = computed(() => subscriber.value?.credits ?? {
        available: 0,
        subscription: 0,
        purchased: 0,
        held: 0,
        plan_per_period: 0,
    });
    const rublesPerCredit = computed(() => subscriber.value?.rubles_per_credit ?? 2);

    return {
        subscriber,
        balance,
        promoBanner,
        subscription,
        daysIndicator,
        hasSeenTour,
        credits,
        rublesPerCredit,
    };
}
