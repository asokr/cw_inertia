<script setup>
import { computed } from "vue";
import { Head } from "@inertiajs/vue3";
import LogisticsTable from "@/components/subscriber/wb/profitability/LogisticsTable.vue";
import OtherOperationsTable from "@/components/subscriber/wb/profitability/OtherOperationsTable.vue";
import ProfitabilityWidget from "@/components/subscriber/wb/profitability/ProfitabilityWidget.vue";
import ReportTotals from "@/components/subscriber/wb/profitability/ReportTotals.vue";
import ReturnsTable from "@/components/subscriber/wb/profitability/ReturnsTable.vue";
import SalesTable from "@/components/subscriber/wb/profitability/SalesTable.vue";
import UpdateDataForm from "@/components/subscriber/wb/profitability/UpdateDataForm.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import JobProgressPanel from "@/components/ui/JobProgressPanel.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import {
    buildProfitabilityProgressDetail,
    PROFITABILITY_JOB_STAGES,
    resolveProfitabilityProgressPercent,
} from "@/config/profitabilityJobStages";
import { useFlashToast } from "@/composables/useFlashToast";
import { useProfitabilityPoll } from "@/composables/useProfitabilityPoll";

const props = defineProps({
    cabinet: { type: Object, required: true },
    jobStatus: { type: Object, default: () => ({ status: "done", error: null }) },
    report: { type: Object, default: null },
    widget: { type: Object, default: null },
    groupMeta: {
        type: Object,
        default: () => ({ sales: 0, returns: 0, logistics: 0, other: 0 }),
    },
    itemsBaseUrl: { type: String, required: true },
    exportStartUrl: { type: String, required: true },
    exportStatusUrl: { type: String, required: true },
    exportDownloadUrl: { type: String, required: true },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Рентабельность Wildberries", href: "/panel/wb/profitability" },
    { label: props.cabinet.name },
];

const { showError } = useFlashToast();

const poll = useProfitabilityPoll({
    onFailed: (message) => {
        showError(message);
    },
});

const isProcessing = computed(() => props.jobStatus?.status === "processing");
const hasReport = computed(() => Boolean(props.report && Object.keys(props.report).length > 0));
const progressDetail = computed(() => buildProfitabilityProgressDetail(props.jobStatus));
const progressPercent = computed(() => resolveProfitabilityProgressPercent(props.jobStatus));

const hasSales = computed(() => (props.groupMeta?.sales ?? 0) > 0);
const hasReturns = computed(() => (props.groupMeta?.returns ?? 0) > 0);
const hasLogistics = computed(() => (props.groupMeta?.logistics ?? 0) > 0);
const hasOther = computed(() => (props.groupMeta?.other ?? 0) > 0);

function onPollingStart() {
    poll.start();
}
</script>

<template>
    <Head :title="`Рентабельность — ${cabinet.name}`" />

    <SubscriberLayout :title="cabinet.name" :breadcrumbs="breadcrumbs">
        <ToolPageHeader title="Рентабельность Wildberries" :description="cabinet.name" />

        <div class="space-y-6">
            <ProfitabilityWidget v-if="widget" :widget="widget" />

            <UpdateDataForm
                :cabinet-id="cabinet.id"
                :submit-label="hasReport ? 'Обновить данные' : 'Получить данные'"
                :processing="isProcessing"
                @polling-start="onPollingStart"
            />

            <JobProgressPanel
                v-if="isProcessing"
                title="Формируем отчёт рентабельности"
                :stages="PROFITABILITY_JOB_STAGES"
                :current-stage="jobStatus.stage || 'queued'"
                :status-label="jobStatus.status_label"
                :progress-percent="progressPercent"
                :detail="progressDetail.detail"
                :waiting-hint="progressDetail.waitingHint"
                :started-at="jobStatus.started_at"
            />

            <template v-if="hasReport">
                <ReportTotals
                    :report="report"
                    :export-start-url="exportStartUrl"
                    :export-status-url="exportStatusUrl"
                    :export-download-url="exportDownloadUrl"
                />

                <SalesTable v-if="hasSales" lazy :items-url="itemsBaseUrl" />
                <ReturnsTable v-if="hasReturns" lazy :items-url="itemsBaseUrl" />
                <LogisticsTable v-if="hasLogistics" lazy :items-url="itemsBaseUrl" />
                <OtherOperationsTable v-if="hasOther" lazy :items-url="itemsBaseUrl" />
            </template>
        </div>
    </SubscriberLayout>
</template>
