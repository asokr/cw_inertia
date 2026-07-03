<script setup>
import { ref } from "vue";
import { Calculator } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import { usePromoCalculatorApi } from "@/composables/usePromoCalculatorApi";

const props = defineProps({
    cabinetId: { type: [Number, null], default: null },
    filePath: { type: String, default: "" },
});

const emit = defineEmits(["calculated", "error"]);

const { calculate } = usePromoCalculatorApi();
const loading = ref(false);

async function runCalculate() {
    if (!props.cabinetId) {
        emit("error", "Выберите кабинет из Ценообразования");
        return;
    }

    if (!props.filePath) {
        emit("error", "Загрузите файл с товарами по акции");
        return;
    }

    loading.value = true;

    try {
        const data = await calculate({
            file: props.filePath,
            cabinetId: props.cabinetId,
        });
        emit("calculated", data);
    } catch (err) {
        emit("error", err?.message ?? "Не удалось выполнить расчёт");
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="space-y-3">
        <h3 class="text-lg font-semibold">Шаг 3: Рассчитайте рентабельность</h3>
        <Button :disabled="loading" @click="runCalculate">
            <Calculator class="mr-2 h-4 w-4" />
            {{ loading ? "Расчёт…" : "Рассчитать" }}
        </Button>
    </div>
</template>