<script setup>
import { computed } from "vue";
import Card from "@/components/ui/Card.vue";
import { formatLimitLabel } from "@/utils/limitLabels";

const props = defineProps({
    remainingLimits: { type: Object, default: () => ({}) },
    /** Server-prepared entries: { key, label, value, hint? }[] */
    remainingLimitsDisplay: { type: Array, default: null },
});

const entries = computed(() => {
    if (Array.isArray(props.remainingLimitsDisplay) && props.remainingLimitsDisplay.length) {
        return props.remainingLimitsDisplay.map((item) => ({
            key: item.key,
            label: item.label,
            value: item.value,
            hint: item.hint ?? null,
        }));
    }

    return Object.entries(props.remainingLimits ?? {})
        .filter(([, value]) => value !== null && value !== undefined)
        .map(([key, value]) => ({
            key,
            label: formatLimitLabel(key),
            value,
            hint: null,
        }));
});
</script>

<template>
    <Card class="subscriber-card--static border-border/70 bg-card/80 p-6 backdrop-blur dark:bg-card/95 dark:backdrop-blur-none">
        <h2 class="mb-4 text-base font-semibold tracking-tight">Остатки лимитов</h2>
        <ul v-if="entries.length" class="divide-y divide-border/60">
            <li
                v-for="item in entries"
                :key="item.key"
                class="flex items-center justify-between gap-3 py-3 text-sm first:pt-0 last:pb-0"
            >
                <span class="text-muted-foreground" :title="item.hint || undefined">{{ item.label }}</span>
                <span class="font-medium tabular-nums">{{ item.value }}</span>
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">
            Активная подписка не найдена или лимиты не настроены.
        </p>
    </Card>
</template>
