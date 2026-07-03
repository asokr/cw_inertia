<script setup>
import { computed, ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { RefreshCw } from "lucide-vue-next";
import Alert from "@/components/ui/Alert.vue";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    cabinetId: { type: Number, required: true },
    report: { type: Object, default: null },
    defaultPeriod: { type: Object, default: () => ({}) },
    isPolling: { type: Boolean, default: false },
    timedOut: { type: Boolean, default: false },
    startUrl: { type: String, required: true },
    refreshUrl: { type: String, required: true },
});

const emit = defineEmits(["polling-start"]);

const datePresets = [
    { label: "7 дней", days: 7 },
    { label: "14 дней", days: 14 },
    { label: "30 дней", days: 30 },
    { label: "Этот месяц", type: "currentMonth" },
];

const selectedPresetLabel = ref("Этот месяц");

const form = useForm({
    begin_date: props.defaultPeriod.begin_date ?? "",
    end_date: props.defaultPeriod.end_date ?? "",
});

function formatDateToIso(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
}

function applyPreset(preset) {
    const today = new Date();
    const end = formatDateToIso(today);
    selectedPresetLabel.value = preset.label;

    if (preset.type === "currentMonth") {
        form.begin_date = formatDateToIso(new Date(today.getFullYear(), today.getMonth(), 1));
        form.end_date = end;
        return;
    }

    const start = new Date(today);
    start.setDate(today.getDate() - preset.days + 1);
    form.begin_date = formatDateToIso(start);
    form.end_date = end;
}

applyPreset(datePresets.find((p) => p.type === "currentMonth") || datePresets[0]);

const selectedDateRangeText = computed(() => {
    if (!form.begin_date || !form.end_date) return "";
    return `Период анализа: с ${form.begin_date} по ${form.end_date}`;
});

const statusLabel = computed(() => {
    const map = { done: "готов", failed: "ошибка", processing: "обработка" };
    return map[props.report?.status] || "неизвестно";
});

const statusVariant = computed(() => {
    const map = { done: "success", failed: "destructive", processing: "default" };
    return map[props.report?.status] || "default";
});

const isBusy = computed(() => props.isPolling || form.processing || props.report?.status === "processing");

function submit() {
    form.post(props.startUrl, {
        preserveScroll: true,
        onSuccess: () => emit("polling-start"),
    });
}

function manualRefresh() {
    router.get(props.refreshUrl, {
        report_id: props.report?.id,
    }, {
        only: ["report", "meta", "nomenclatures", "nomenclaturesMeta", "analyses", "analysesMeta"],
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            if (props.report?.status === "processing") emit("polling-start");
        },
    });
}

function formatDateTime(value) {
    if (!value) return "—";
    return new Date(value).toLocaleString("ru-RU");
}
</script>

<template>
    <Card class="overflow-hidden p-4">
        <div v-if="isPolling" class="mb-3 h-1 w-full animate-pulse rounded bg-primary" />

        <div class="space-y-4">
            <div class="flex flex-wrap gap-2">
                <span class="text-sm text-muted-foreground">Быстрый выбор:</span>
                <Button
                    v-for="preset in datePresets"
                    :key="preset.label"
                    size="sm"
                    :variant="selectedPresetLabel === preset.label ? 'default' : 'outline'"
                    :disabled="isBusy"
                    @click="applyPreset(preset)"
                >
                    {{ preset.label }}
                </Button>
            </div>

            <p v-if="selectedDateRangeText" class="text-sm text-muted-foreground">{{ selectedDateRangeText }}</p>

            <p class="text-sm text-muted-foreground">
                На этом шаге формируется отчёт по кабинету. ИИ-анализ запускается ниже, после сбора данных.
            </p>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <Button :disabled="isBusy" @click="submit">
                    Собрать данные кабинета
                </Button>

                <div v-if="report?.id" class="flex flex-wrap items-center gap-3">
                    <div class="text-xs text-muted-foreground">
                        <div v-if="report.updated_at">Обновлен: {{ formatDateTime(report.updated_at) }}</div>
                    </div>
                    <Badge :variant="statusVariant">{{ statusLabel }}</Badge>
                    <Button variant="outline" size="sm" :disabled="isPolling" @click="manualRefresh">
                        <RefreshCw class="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <Alert v-if="report?.status === 'failed'" variant="destructive">
                {{ report.error || "Ошибка обработки отчёта" }}
            </Alert>
            <Alert v-if="timedOut">
                Время ожидания истекло. Обновите статус вручную или перезапустите сбор данных.
            </Alert>
        </div>
    </Card>
</template>