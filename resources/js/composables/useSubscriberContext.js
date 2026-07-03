import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

export function useSubscriberContext() {
    const page = usePage();

    const subscriber = computed(() => page.props.subscriber ?? null);
    const balance = computed(() => subscriber.value?.balance ?? 0);
    const notify = computed(() => subscriber.value?.notify ?? null);
    const subscription = computed(() => subscriber.value?.subscription ?? null);
    const hasSeenTour = computed(() => subscriber.value?.has_seen_tour ?? false);

    return {
        subscriber,
        balance,
        notify,
        subscription,
        hasSeenTour,
    };
}