<script setup>
import { ref } from "vue";
import Button from "@/components/ui/Button.vue";
import { usePromoCalculatorApi } from "@/composables/usePromoCalculatorApi";

const props = defineProps({
    data: { type: Array, default: () => [] },
});

const emit = defineEmits(["error"]);

const { exportResults } = usePromoCalculatorApi();
const loading = ref(false);

async function download() {
    if (!props.data.length) return;

    loading.value = true;

    try {
        const link = await exportResults(props.data);
        if (link) {
            window.open(link, "_blank");
        }
    } catch (err) {
        emit("error", err?.message ?? "Не удалось скачать данные");
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <Button variant="outline" size="sm" :disabled="loading || !data.length" @click="download">
        {{ loading ? "Формирование…" : "Скачать данные" }}
    </Button>
</template>