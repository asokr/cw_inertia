import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

export function useSubscriberContext() {
    const page = usePage();

    const subscriber = computed(() => page.props.subscriber ?? null);
    const balance = computed(() => subscriber.value?.balance ?? 0);
    const promoBanner = computed(() => subscriber.value?.promo_banner ?? null);
    const subscription = computed(() => subscriber.value?.subscription ?? null);
    const hasSeenTour = computed(() => subscriber.value?.has_seen_tour ?? false);

    return {
        subscriber,
        balance,
        promoBanner,
        subscription,
        hasSeenTour,
    };
}