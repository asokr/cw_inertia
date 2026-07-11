<script setup>
defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: "",
    },
    accent: {
        type: String,
        default: "",
    },
});
</script>

<template>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="space-y-1">
            <h2 class="text-xl font-semibold tracking-tight sm:text-2xl">
                {{ title }}
                <span
                    v-if="accent"
                    class="bg-gradient-to-r from-foreground to-primary bg-clip-text text-transparent"
                >
                    {{ accent }}
                </span>
            </h2>
            <p v-if="description" class="text-sm leading-relaxed text-muted-foreground sm:text-base">
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>