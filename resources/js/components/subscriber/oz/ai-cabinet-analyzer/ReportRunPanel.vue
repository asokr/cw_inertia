<script setup>
import { computed, ref, watch } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import {
    CalendarRange,
    CheckCircle2,
    Database,
    Loader2,
    RefreshCw,
    AlertCircle,
} from "lucide-vue-next";
import Alert from "@/components/ui/Alert.vue";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Separator from "@/components/ui/Separator.vue";
import { LONG_RUNNING_MESSAGE } from "@/composables/useAiCabinetReportPoll";
import { useFlashToast } from "@/composables/useFlashToast";
import { formatDisplayDateRange } from "@/utils/aiCabinetAnalysisDisplay";

const props = defineProps({
    report: { type: Object, default: null },
    defaultPeriod: { type: Object, default: () => ({}) },
    isPolling: { type: Boolean, default: false },
    /** soft hint that collection runs long — not a hard failure */
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

const selectedDateRangeText = computed(() =>
    formatDisplayDateRange(form.begin_date, form.end_date),
);

const statusLabel = computed(() => {
    const map = { done: "Готово", failed: "Ошибка", processing: "В обработке" };
    return map[props.report?.status] || "Не собраны";
});

const statusVariant = computed(() => {
    const map = { done: "success", failed: "destructive", processing: "default" };
    return map[props.report?.status] || "secondary";
});

const isProcessing = computed(() => props.report?.status === "processing");
const isDone = computed(() => props.report?.status === "done");
const isFailed = computed(() => props.report?.status === "failed");
const hasReport = computed(() => Boolean(props.report?.id));

/** Блокируем только на время submit / активного опроса. Не блокируем навсегда. */
const isBusy = computed(() => form.processing || (isProcessing.value && props.isPolling));

const showLongRunningBanner = computed(
    () => props.timedOut && isProcessing.value,
);

const showProcessingBanner = computed(
    () => isProcessing.value && props.isPolling && !props.timedOut,
);

const ctaLabel = computed(() => {
    if (isBusy.value && isProcessing.value) return "Идёт сбор…";
    if (isDone.value) return "Обновить данные";
    if (isFailed.value) return "Собрать заново";
    if (isProcessing.value) return "Запустить сбор заново";
    return "Собрать данные";
});

const updatedAtDate = computed(() => {
    if (!props.report?.updated_at) return null;
    return new Date(props.report.updated_at).toLocaleDateString("ru-RU", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
    });
});

const updatedAtTime = computed(() => {
    if (!props.report?.updated_at) return null;
    return new Date(props.report.updated_at).toLocaleTimeString("ru-RU", {
        hour: "2-digit",
        minute: "2-digit",
    });
});

/** Period covered by the collected report snapshot */
const reportPeriodText = computed(() =>
    formatDisplayDateRange(props.report?.begin_date, props.report?.end_date),
);

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
        only: ["report", "meta", "products", "productsMeta", "productFilters", "analyses", "analysesMeta"],
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            if (props.report?.status === "processing") {
                emit("polling-start");
            }
        },
    });
}

const { showError } = useFlashToast();

watch(
    () => props.report?.status,
    (status) => {
        if (status === "failed") {
            showError(props.report?.error || "Ошибка обработки отчёта");
        }
    },
    { immediate: true },
);
</script>

<template>
    <Card class="overflow-hidden">
        <div
            v-if="isPolling && isProcessing"
            class="h-1 w-full animate-pulse bg-primary"
        />

        <div class="space-y-8 p-5 sm:p-6 md:p-8">
            <!-- Step 1: Period -->
            <section class="space-y-5">
                <div class="flex items-start gap-3">
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                    >
                        <CalendarRange class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 space-y-0.5">
                        <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                            Шаг 1
                        </p>
                        <h2 class="text-xl font-semibold tracking-tight sm:text-2xl">
                            Период анализа
                        </h2>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="preset in datePresets"
                        :key="preset.label"
                        size="sm"
                        class="rounded-full px-4"
                        :variant="selectedPresetLabel === preset.label ? 'default' : 'outline'"
                        :disabled="isBusy"
                        @click="applyPreset(preset)"
                    >
                        {{ preset.label }}
                    </Button>
                </div>

                <p
                    v-if="selectedDateRangeText"
                    class="text-lg font-medium tabular-nums tracking-tight sm:text-xl"
                >
                    {{ selectedDateRangeText }}
                </p>
            </section>

            <Separator />

            <!-- Step 2: Cabinet data -->
            <section class="space-y-5">
                <div class="flex items-start gap-3">
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                    >
                        <Database class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 space-y-0.5">
                        <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                            Шаг 2
                        </p>
                        <h2 class="text-xl font-semibold tracking-tight sm:text-2xl">
                            Данные кабинета
                        </h2>
                    </div>
                </div>

                <Alert v-if="showLongRunningBanner" variant="warning">
                    {{ LONG_RUNNING_MESSAGE }}
                </Alert>

                <Alert v-else-if="showProcessingBanner">
                    Идёт сбор данных кабинета. Из‑за ограничений Ozon это может занять
                    продолжительное время. Страницу можно закрыть — сбор завершится сам,
                    статус обновится при следующем открытии.
                </Alert>

                <Alert v-else-if="isFailed" variant="destructive">
                    {{ report.error || "Предыдущий сбор данных завершился с ошибкой. Можно запустить заново." }}
                </Alert>

                <!-- Ready: calm default state (most common) -->
                <div
                    v-if="isDone"
                    class="flex flex-col gap-4 rounded-xl border bg-card p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5 sm:py-4"
                >
                    <div class="space-y-1.5 text-sm">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                            <span class="inline-flex items-center gap-1.5 font-medium text-foreground">
                                <CheckCircle2 class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                                Данные готовы
                            </span>
                            <Badge variant="success">{{ statusLabel }}</Badge>
                        </div>
                        <p v-if="reportPeriodText" class="text-muted-foreground">
                            Период данных:
                            <span class="font-medium tabular-nums text-foreground">{{ reportPeriodText }}</span>
                        </p>
                        <p v-if="updatedAtDate" class="text-muted-foreground">
                            Обновлено
                            <span class="tabular-nums text-foreground/80">{{ updatedAtDate }}</span>
                            <span v-if="updatedAtTime" class="ml-1 tabular-nums text-foreground/80">{{ updatedAtTime }}</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 sm:shrink-0">
                        <Button variant="outline" size="sm" :disabled="isBusy" @click="submit">
                            <RefreshCw class="mr-1.5 h-3.5 w-3.5" />
                            {{ ctaLabel }}
                        </Button>
                    </div>
                </div>

                <!-- Hero state: processing -->
                <div
                    v-else-if="isProcessing"
                    class="flex flex-col gap-5 rounded-2xl border border-primary/20 bg-primary/5 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6"
                >
                    <div class="flex items-start gap-4">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Loader2 class="h-5 w-5 animate-spin" />
                        </div>
                        <div class="space-y-2">
                            <p class="text-lg font-semibold tracking-tight">Собираем данные…</p>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                <span>Статус обновляется автоматически</span>
                                <Badge :variant="statusVariant">{{ statusLabel }}</Badge>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 sm:shrink-0">
                        <Button variant="outline" :disabled="isBusy" @click="submit">
                            {{ ctaLabel }}
                        </Button>
                        <Button
                            v-if="hasReport"
                            variant="ghost"
                            size="icon"
                            :disabled="isPolling"
                            title="Обновить статус"
                            @click="manualRefresh"
                        >
                            <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': isPolling }" />
                        </Button>
                    </div>
                </div>

                <!-- Hero state: failed -->
                <div
                    v-else-if="isFailed"
                    class="flex flex-col gap-5 rounded-2xl border border-destructive/20 bg-destructive/5 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6"
                >
                    <div class="flex items-start gap-4">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-destructive/10 text-destructive"
                        >
                            <AlertCircle class="h-5 w-5" />
                        </div>
                        <div class="space-y-2">
                            <p class="text-lg font-semibold tracking-tight">Не удалось собрать данные</p>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                <span>Можно запустить сбор заново</span>
                                <Badge :variant="statusVariant">{{ statusLabel }}</Badge>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 sm:shrink-0">
                        <Button :disabled="isBusy" @click="submit">
                            {{ ctaLabel }}
                        </Button>
                        <Button
                            v-if="hasReport"
                            variant="ghost"
                            size="icon"
                            :disabled="isPolling"
                            title="Обновить статус"
                            @click="manualRefresh"
                        >
                            <RefreshCw class="h-4 w-4" />
                        </Button>
                    </div>
                </div>

                <!-- Hero state: empty -->
                <div
                    v-else
                    class="flex flex-col gap-5 rounded-2xl border border-dashed bg-muted/20 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6"
                >
                    <div class="flex items-start gap-4">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                        >
                            <Database class="h-5 w-5" />
                        </div>
                        <div class="space-y-1.5">
                            <p class="text-lg font-semibold tracking-tight">Данные не собраны</p>
                            <p class="max-w-md text-sm leading-relaxed text-muted-foreground">
                                На этом этапе анализ собирает каталог товаров кабинета Ozon. Период сохраняется для будущей аналитики.
                            </p>
                        </div>
                    </div>
                    <Button class="sm:shrink-0" :disabled="isBusy" @click="submit">
                        {{ ctaLabel }}
                    </Button>
                </div>
            </section>
        </div>
    </Card>
</template>
