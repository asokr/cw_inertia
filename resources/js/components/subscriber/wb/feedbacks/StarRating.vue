<script setup>
import { Star } from "lucide-vue-next";

defineProps({
    value: { type: Number, default: 0 },
    max: { type: Number, default: 5 },
});
</script>

<template>
    <div class="flex items-center gap-0.5">
        <Star
            v-for="star in max"
            :key="star"
            class="h-4 w-4"
            :class="star <= value ? 'fill-amber-400 text-amber-400' : 'text-muted-foreground/40'"
        />
    </div>
</template>