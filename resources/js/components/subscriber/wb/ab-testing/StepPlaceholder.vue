<script setup>
import { Construction } from "lucide-vue-next";
import Card from "@/components/ui/Card.vue";

defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: "Этот шаг будет реализован позже.",
    },
});
</script>

<template>
    <Card class="flex flex-col items-center justify-center gap-3 p-10 text-center">
        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
            <Construction class="h-5 w-5 text-muted-foreground" />
        </div>
        <div class="space-y-1">
            <h3 class="text-lg font-semibold">{{ title }}</h3>
            <p class="max-w-md text-sm text-muted-foreground">{{ description }}</p>
        </div>
    </Card>
</template>
