<script setup>
import { Sparkles } from "lucide-vue-next";
import { computed } from "vue";
import { useSubscriberContext } from "@/composables/useSubscriberContext";

defineProps({
    currentPlanName: { type: String, default: null },
});

const { promoBanner } = useSubscriberContext();

const isTrial = computed(() => {
    const variant = promoBanner.value?.variant;
    return variant === "trial_active" || variant === "trial_expired";
});
</script>

<template>
    <section class="plans-hero">
        <p
            v-if="isTrial && promoBanner?.days_left != null"
            class="inline-flex items-center gap-2 rounded-full border border-border/70 bg-card/80 px-3 py-1 text-xs font-medium text-muted-foreground backdrop-blur"
        >
            <Sparkles class="h-3.5 w-3.5 text-primary" />
            Пробный период · {{ promoBanner.days_left }} дн.
        </p>

        <h1 class="mt-4 text-3xl font-bold tracking-tight sm:text-4xl">
            Выберите тариф
            <span class="block bg-gradient-to-r from-foreground to-primary bg-clip-text text-transparent">
                для роста на маркетплейсах
            </span>
        </h1>

        <p class="mt-4 max-w-2xl text-base leading-relaxed text-muted-foreground sm:text-lg">
            <template v-if="currentPlanName">
                Сейчас у вас «{{ currentPlanName }}». Смените тариф в один клик — лимиты обновятся сразу после оплаты.
            </template>
            <template v-else>
                Полный доступ к инструментам Wildberries, Ozon и ИИ — без ограничений пробного периода.
            </template>
        </p>
    </section>
</template>