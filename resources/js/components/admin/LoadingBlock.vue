<script setup>
import Skeleton from "@/components/ui/Skeleton.vue";

defineProps({
    rows: { type: Number, default: 5 },
});
</script>

<template>
    <div class="space-y-3">
        <Skeleton v-for="n in rows" :key="n" class="h-9 w-full" />
    </div>
</template>