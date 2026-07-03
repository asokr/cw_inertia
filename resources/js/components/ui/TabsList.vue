<script setup>
import { cn } from "@/lib/utils";

defineProps({
    class: { type: String, default: "" },
});
</script>

<template>
    <div :class="cn('inline-flex h-9 items-center justify-center rounded-lg bg-muted p-1 text-muted-foreground', $props.class)">
        <slot />
    </div>
</template>