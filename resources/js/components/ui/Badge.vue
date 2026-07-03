<script setup>
import { computed } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({
    variant: {
        type: String,
        default: "default",
    },
});

const classes = computed(() => {
    const variants = {
        default: "bg-primary text-primary-foreground",
        secondary: "bg-secondary text-secondary-foreground",
        outline: "border border-input text-foreground",
        success: "bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300",
        warning: "bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300",
        destructive: "bg-destructive/10 text-destructive",
    };

    return cn(
        "inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium",
        variants[props.variant]
    );
});
</script>

<template>
    <span :class="classes">
        <slot />
    </span>
</template>