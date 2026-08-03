<script setup>
import { ref } from "vue";
import { Calculator } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import { usePromoCalculatorApi } from "@/composables/usePromoCalculatorApi";

const props = defineProps({
    filePath: { type: String, default: "" },
    cabinetName: { type: String, default: "" },
});

const emit = defineEmits(["calculated", "error"]);

const { calculate } = usePromoCalculatorApi();
const loading = ref(false);

async function runCalculate() {
    if (!props.filePath) {
        emit("error", "Загрузите файл с товарами по акции");
        return;
    }

    loading.value = true;

    try {
        const data = await calculate({
            file: props.filePath,
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
    <Card class="overflow-hidden">
        <div class="border-b border-border/60 px-5 py-4">
            <p class="text-xs font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                Шаг 2
            </p>
            <h3 class="mt-1 text-base font-semibold">Рассчитайте рентабельность</h3>
            <p class="mt-1 text-sm text-muted-foreground">
                Сопоставим отчёт с данными ценообразования
                <span v-if="cabinetName" class="font-medium text-foreground">«{{ cabinetName }}»</span>.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3 p-5">
            <Button :disabled="loading || !filePath" @click="runCalculate">
                <Calculator class="mr-2 h-4 w-4" />
                {{ loading ? "Расчёт…" : "Рассчитать" }}
            </Button>
            <p v-if="!filePath" class="text-sm text-muted-foreground">
                Сначала загрузите отчёт на шаге 1.
            </p>
        </div>
    </Card>
</template>
