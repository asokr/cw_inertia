<script setup>
import { AlertTriangle, Clock, Sparkles } from "lucide-vue-next";
import { computed } from "vue";
import Button from "@/components/ui/Button.vue";
import { useSubscriberContext } from "@/composables/useSubscriberContext";

const { promoBanner } = useSubscriberContext();

const isUrgent = computed(() => {
    const variant = promoBanner.value?.variant;
    return variant === "trial_expired" || variant === "subscription_expired" || variant === "no_subscription";
});

const icon = computed(() => {
    const variant = promoBanner.value?.variant;
    if (variant === "trial_active") return Clock;
    if (isUrgent.value) return AlertTriangle;
    return Sparkles;
});
</script>

<template>
    <div
        v-if="promoBanner"
        class="relative z-10 border-b px-4 py-2.5"
        :class="
            isUrgent
                ? 'border-amber-500/30 bg-amber-500/10 dark:bg-amber-500/15'
                : 'border-primary/20 bg-primary/5 dark:bg-primary/10'
        "
    >
        <div class="mx-auto flex max-w-[1920px] flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
            <div class="flex min-w-0 items-start gap-2.5 sm:items-center">
                <component
                    :is="icon"
                    class="mt-0.5 h-4 w-4 shrink-0 sm:mt-0"
                    :class="isUrgent ? 'text-amber-600 dark:text-amber-400' : 'text-primary'"
                />
                <p class="text-sm leading-snug" :class="isUrgent ? 'text-amber-950 dark:text-amber-100' : 'text-foreground'">
                    {{ promoBanner.message }}
                </p>
            </div>
            <Button
                :href="promoBanner.cta_href"
                size="sm"
                :variant="isUrgent ? 'default' : 'outline'"
                class="shrink-0 self-start sm:self-auto"
                :class="isUrgent ? 'bg-amber-600 hover:bg-amber-600/90 dark:bg-amber-500 dark:hover:bg-amber-500/90' : ''"
            >
                {{ promoBanner.cta_label }}
            </Button>
        </div>
    </div>
</template>