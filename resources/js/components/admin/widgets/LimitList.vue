<script setup>
import { computed } from "vue";
import { buildNormalizedLimitItems, formatLimitLabel } from "@/utils/limitLabels";

const props = defineProps({
    base: { type: Object, default: () => ({}) },
    extra: { type: Object, default: () => ({}) },
    tariff: { type: Object, default: () => ({}) },
    labeled: { type: Boolean, default: true },
    showTariff: { type: Boolean, default: true },
    /** Optional map slug → name (from server / extra_limits). */
    labelsMap: { type: Object, default: null },
});

const items = computed(() =>
    buildNormalizedLimitItems(props.base, props.extra, props.tariff).map((item) => ({
        ...item,
        label: props.labeled ? formatLimitLabel(item.name, props.labelsMap) : item.name,
    }))
);
</script>

<template>
    <div class="space-y-1">
        <div v-for="item in items" :key="item.name" class="flex items-center justify-between gap-3 text-sm">
            <span class="text-muted-foreground">{{ item.label }}</span>
            <span class="shrink-0 font-semibold tabular-nums">
                {{ item.base }}
                <span
                    v-if="showTariff && item.tariff !== undefined && item.tariff !== null"
                    class="font-normal text-muted-foreground"
                >
                    / {{ item.tariff }}
                </span>
                <span v-if="item.extra > 0" class="text-primary">+{{ item.extra }}</span>
            </span>
        </div>
    </div>
</template>
