<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    hint: { type: String, default: "" },
    icon: { type: [Object, Function], default: null },
});
</script>

<template>
    <div
        class="group rounded-lg border border-border/70 bg-card/80 p-4 backdrop-blur transition hover:border-primary/30 hover:shadow-md hover:shadow-primary/5 dark:bg-card/95 dark:backdrop-blur-none dark:hover:shadow-black/20"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 space-y-1">
                <p class="text-xs text-muted-foreground">{{ label }}</p>
                <p class="truncate text-xl font-semibold tabular-nums">{{ value }}</p>
                <p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
            </div>
            <div
                v-if="icon"
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary transition group-hover:bg-primary/15"
            >
                <component :is="icon" class="h-5 w-5" />
            </div>
        </div>
    </div>
</template>