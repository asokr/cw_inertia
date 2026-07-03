<script setup>
import { cn } from "@/lib/utils";

defineProps({
    orientation: { type: String, default: "horizontal" },
});
</script>

<template>
    <div
        :class="cn(
            'shrink-0 bg-border',
            orientation === 'horizontal' ? 'h-px w-full' : 'h-full w-px'
        )"
        role="separator"
    />
</template>