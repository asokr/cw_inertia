<script setup>
import { computed } from "vue";
import Badge from "@/components/ui/Badge.vue";

const props = defineProps({
    status: {
        type: String,
        default: "pending",
    },
});

const config = computed(() => {
    const map = {
        pending: { label: "Ожидание", variant: "secondary" },
        queued: { label: "В очереди", variant: "secondary" },
        running: { label: "Выполняется", variant: "default" },
        processing: { label: "Обработка", variant: "default" },
        done: { label: "Готово", variant: "default" },
        completed: { label: "Завершено", variant: "default" },
        success: { label: "Успешно", variant: "default" },
        failed: { label: "Ошибка", variant: "destructive" },
        error: { label: "Ошибка", variant: "destructive" },
    };

    const key = String(props.status || "pending").toLowerCase();

    return map[key] ?? { label: props.status, variant: "secondary" };
});
</script>

<template>
    <Badge :variant="config.variant">{{ config.label }}</Badge>
</template>