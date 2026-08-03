<script setup>
import { computed, onUnmounted, ref } from "vue";
import { Head } from "@inertiajs/vue3";
import CabinetToolbar from "@/components/subscriber/wb/price-calc/CabinetToolbar.vue";
import CardsTable from "@/components/subscriber/wb/price-calc/CardsTable.vue";
import PriceCalcFaq from "@/components/subscriber/wb/price-calc/PriceCalcFaq.vue";
import SettingsDialog from "@/components/subscriber/wb/price-calc/SettingsDialog.vue";
import WorkflowAlert from "@/components/subscriber/wb/price-calc/WorkflowAlert.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import JobProgressPanel from "@/components/ui/JobProgressPanel.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import {
    buildPriceCalcProgressDetail,
    PRICE_CALC_JOB_STAGES,
    PRICE_CALC_SYNC_STAGES,
    resolvePriceCalcProgressPercent,
} from "@/config/priceCalcJobStages";
import { useFlashToast } from "@/composables/useFlashToast";
import { usePriceCalcPoll } from "@/composables/usePriceCalcPoll";

const props = defineProps({
    cabinet: { type: Object, required: true },
    settings: { type: Object, default: null },
    cards: { type: Array, default: () => [] },
    cardsMeta: { type: Object, default: () => ({}) },
    cardsError: { type: String, default: null },
    operationLock: {
        type: Object,
        default: () => ({ busy: false, retry_after: 0, reason: null }),
    },
    jobStatus: {
        type: Object,
        default: () => ({ status: "done", error: null }),
    },
    filters: { type: Object, default: () => ({}) },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Ценообразование", href: "/panel/wb/price-calc" },
    { label: props.cabinet.name },
];

const settingsOpen = ref(false);
const completionBanner = ref(null);
let completionTimer = null;

const baseUrl = `/panel/wb/price-calc`;

const { watchPropToast, showError, showSuccess } = useFlashToast();
watchPropToast(() => props.cardsError);

function clearCompletionTimer() {
    if (completionTimer) {
        window.clearTimeout(completionTimer);
        completionTimer = null;
    }
}

function showCompletionBanner(payload) {
    clearCompletionTimer();
    completionBanner.value = payload;
    completionTimer = window.setTimeout(() => {
        completionBanner.value = null;
        completionTimer = null;
    }, 8000);
}

const poll = usePriceCalcPoll({
    onFailed: (message) => {
        showError(message);
        showCompletionBanner({
            failed: true,
            statusLabel: message,
            error: message,
            startedAt: props.jobStatus?.started_at ?? null,
            stage: props.jobStatus?.stage || "queued",
            operation: props.jobStatus?.operation || "import_excel",
        });
    },
    onSuccess: (message, meta = {}) => {
        if (meta.toast !== false) {
            showSuccess(message);
        }
        showCompletionBanner({
            failed: false,
            completed: true,
            statusLabel: message,
            startedAt: props.jobStatus?.started_at ?? null,
            stage: "done",
            operation: props.jobStatus?.operation || "import_excel",
        });
    },
});

const isProcessing = computed(() => props.jobStatus?.status === "processing");
const showProgress = computed(() => isProcessing.value || Boolean(completionBanner.value));

const progressDetail = computed(() => buildPriceCalcProgressDetail(props.jobStatus));
const progressPercent = computed(() => {
    if (completionBanner.value?.completed) {
        return 100;
    }
    return resolvePriceCalcProgressPercent(props.jobStatus);
});
const progressStages = computed(() => {
    const operation =
        completionBanner.value?.operation || props.jobStatus?.operation || "import_excel";
    return operation === "sync" ? PRICE_CALC_SYNC_STAGES : PRICE_CALC_JOB_STAGES;
});
const progressTitle = computed(() => {
    const operation =
        completionBanner.value?.operation || props.jobStatus?.operation || "import_excel";
    return operation === "sync" ? "Обновляем список товаров" : "Импорт Excel и пересчёт цен";
});

const panelStatusLabel = computed(() => {
    if (completionBanner.value) {
        return completionBanner.value.statusLabel;
    }
    return props.jobStatus?.status_label;
});

const panelStage = computed(() => {
    if (completionBanner.value) {
        return completionBanner.value.stage;
    }
    return props.jobStatus?.stage || "queued";
});

const panelStartedAt = computed(() => {
    if (completionBanner.value?.startedAt) {
        return completionBanner.value.startedAt;
    }
    return props.jobStatus?.started_at;
});

function onJobStarted() {
    clearCompletionTimer();
    completionBanner.value = null;
    poll.start();
}

onUnmounted(() => {
    clearCompletionTimer();
});
</script>

<template>
    <Head :title="`Ценообразование — ${cabinet.name}`" />

    <SubscriberLayout :title="cabinet.name" :breadcrumbs="breadcrumbs">
        <ToolPageHeader title="Ценообразование" :description="cabinet.name" />

        <div class="space-y-4">
            <CabinetToolbar
                :cabinet="cabinet"
                :cards-meta="cardsMeta"
                :operation-lock="operationLock"
                :job-processing="isProcessing"
                :sync-url="`${baseUrl}/sync`"
                :import-volume-url="`${baseUrl}/import-volume`"
                :import-excel-url="`${baseUrl}/import-excel`"
                :export-excel-url="`${baseUrl}/export-excel`"
                @open-settings="settingsOpen = true"
                @job-started="onJobStarted"
            />

            <JobProgressPanel
                v-if="showProgress"
                :title="progressTitle"
                :stages="progressStages"
                :current-stage="panelStage"
                :status-label="panelStatusLabel"
                :progress-percent="progressPercent"
                :detail="progressDetail.detail"
                :waiting-hint="progressDetail.waitingHint"
                :started-at="panelStartedAt"
                :failed="Boolean(completionBanner?.failed)"
                :error="completionBanner?.error || jobStatus?.error"
                :completed="Boolean(completionBanner?.completed)"
            />

            <WorkflowAlert />

            <CardsTable
                :items="cards"
                :settings="settings ?? {}"
                :cards-meta="cardsMeta"
                :filters="filters"
                :show-url="baseUrl"
            />
        </div>

        <SettingsDialog
            v-model:open="settingsOpen"
            :settings="settings"
            :save-url="`${baseUrl}/settings`"
        />

        <div class="mt-8">
            <PriceCalcFaq />
        </div>
    </SubscriberLayout>
</template>
