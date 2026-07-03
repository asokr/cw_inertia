<script setup>
import { computed } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({
    variant: { type: String, default: "default" },
});

const classes = computed(() => {
    const variants = {
        default: "bg-background text-foreground border",
        destructive: "border-destructive/50 text-destructive bg-destructive/10",
        success: "border-emerald-500/30 text-emerald-700 bg-emerald-50 dark:text-emerald-300 dark:bg-emerald-950/30",
    };
    return cn("relative w-full rounded-lg px-4 py-3 text-sm", variants[props.variant]);
});
</script>

<template>
    <div :class="classes" role="alert">
        <slot />
    </div>
</template>