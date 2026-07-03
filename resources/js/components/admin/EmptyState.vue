<script setup>
defineProps({
    title: { type: String, default: "Нет данных" },
    description: String,
});
</script>

<template>
    <div class="flex flex-col items-center justify-center rounded-lg border border-dashed py-12 text-center">
        <p class="text-sm font-medium">{{ title }}</p>
        <p v-if="description" class="mt-1 max-w-sm text-sm text-muted-foreground">{{ description }}</p>
        <div v-if="$slots.action" class="mt-4">
            <slot name="action" />
        </div>
    </div>
</template>