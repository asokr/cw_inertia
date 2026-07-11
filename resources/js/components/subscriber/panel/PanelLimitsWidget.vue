<script setup>
import { computed } from "vue";
import Card from "@/components/ui/Card.vue";
import { formatLimitLabel } from "@/utils/limitLabels";

const props = defineProps({
    remainingLimits: { type: Object, default: () => ({}) },
});

const entries = computed(() =>
    Object.entries(props.remainingLimits ?? {}).filter(([, value]) => value !== null && value !== undefined)
);
</script>

<template>
    <Card class="subscriber-card--static border-border/70 bg-card/80 p-6 backdrop-blur dark:bg-card/95 dark:backdrop-blur-none">
        <h2 class="mb-4 text-base font-semibold tracking-tight">Остатки лимитов</h2>
        <ul v-if="entries.length" class="divide-y divide-border/60">
            <li
                v-for="[key, value] in entries"
                :key="key"
                class="flex items-center justify-between gap-3 py-3 text-sm first:pt-0 last:pb-0"
            >
                <span class="text-muted-foreground">{{ formatLimitLabel(key) }}</span>
                <span class="font-medium tabular-nums">{{ value }}</span>
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">
            Активная подписка не найдена или лимиты не настроены.
        </p>
    </Card>
</template>