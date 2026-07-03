<script setup>
import { computed } from "vue";

const props = defineProps({
    value: {
        type: Number,
        default: 0,
    },
    label: {
        type: String,
        default: "",
    },
    indeterminate: Boolean,
});

const clamped = computed(() => Math.min(100, Math.max(0, props.value)));
</script>

<template>
    <div class="space-y-2">
        <div v-if="label" class="text-sm text-muted-foreground">{{ label }}</div>
        <div class="h-2 overflow-hidden rounded-full bg-muted">
            <div
                class="h-full rounded-full bg-primary transition-all duration-200"
                :class="indeterminate ? 'w-1/3 animate-pulse' : ''"
                :style="indeterminate ? undefined : { width: `${clamped}%` }"
            />
        </div>
        <div v-if="!indeterminate" class="text-xs text-muted-foreground">{{ clamped }}%</div>
    </div>
</template>