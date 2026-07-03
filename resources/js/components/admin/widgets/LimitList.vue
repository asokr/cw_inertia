<script setup>
import { computed } from "vue";

const props = defineProps({
    base: { type: Object, default: () => ({}) },
    extra: { type: Object, default: () => ({}) },
});

const items = computed(() => {
    const keys = Array.from(new Set([...Object.keys(props.base || {}), ...Object.keys(props.extra || {})]));

    return keys.map((name) => ({
        name,
        base: props.base?.[name] ?? 0,
        extra: props.extra?.[name] ?? 0,
    }));
});
</script>

<template>
    <div class="space-y-1">
        <div v-for="item in items" :key="item.name" class="flex items-center justify-between text-sm">
            <span class="text-muted-foreground">{{ item.name }}</span>
            <span class="font-semibold">
                {{ item.base }}
                <span v-if="item.extra > 0" class="text-primary">+{{ item.extra }}</span>
            </span>
        </div>
    </div>
</template>