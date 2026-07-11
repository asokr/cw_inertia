<script setup>
import { computed } from "vue";
import { Head } from "@inertiajs/vue3";
import AiAnalysisSection from "@/components/subscriber/wb/ai-cabinet-analyzer/AiAnalysisSection.vue";
import NomenclaturesTable from "@/components/subscriber/wb/ai-cabinet-analyzer/NomenclaturesTable.vue";
import ReportRunPanel from "@/components/subscriber/wb/ai-cabinet-analyzer/ReportRunPanel.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useAiCabinetReportPoll } from "@/composables/useAiCabinetReportPoll";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    cabinet: { type: Object, required: true },
    report: { type: Object, default: null },
    meta: { type: Object, default: null },
    nomenclatures: { type: Array, default: () => [] },
    nomenclaturesMeta: { type: Object, default: () => ({}) },
    nomenclatureFilters: { type: Object, default: () => ({}) },
    templates: { type: Array, default: () => [] },
    analyses: { type: Array, default: () => [] },
    analysesMeta: { type: Object, default: () => ({}) },
    defaultPeriod: { type: Object, default: () => ({}) },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "ИИ анализ кабинета Wildberries", href: "/panel/wb/ai-cabinet-analyzer" },
    { label: props.cabinet.name },
];

const { showError, watchPropToast } = useFlashToast();

const poll = useAiCabinetReportPoll({
    onFailed: (message) => {
        showError(message);
    },
});

const showUrl = computed(() => `/panel/wb/ai-cabinet-analyzer/cabinets/${props.cabinet.id}`);
const startUrl = computed(() => `${showUrl.value}/reports`);
const isReportDone = computed(() => props.report?.status === "done");
const hasMeta = computed(() => Boolean(props.meta && Object.keys(props.meta).length > 0));
const warnings = computed(() => (Array.isArray(props.meta?.warnings) ? props.meta.warnings : []));

function onPollingStart() {
    poll.start();
}

watchPropToast(() => warnings.value, "default");
</script>

<template>
    <Head :title="`ИИ анализ кабинета — ${cabinet.name}`" />

    <SubscriberLayout :title="cabinet.name" :breadcrumbs="breadcrumbs">
        <ToolPageHeader title="ИИ анализ кабинета Wildberries" :description="cabinet.name" />

        <div class="space-y-6">
            <ReportRunPanel
                :cabinet-id="cabinet.id"
                :report="report"
                :default-period="defaultPeriod"
                :is-polling="poll.isPolling"
                :timed-out="poll.timedOut"
                :start-url="startUrl"
                :refresh-url="showUrl"
                @polling-start="onPollingStart"
            />

            <AiAnalysisSection
                :report="report"
                :templates="templates"
                :analyses="analyses"
                :analyses-meta="analysesMeta"
                :show-url="showUrl"
            />

            <template v-if="hasMeta">
                <NomenclaturesTable
                    v-if="isReportDone"
                    :show-url="showUrl"
                    :report-id="report?.id"
                    :items="nomenclatures"
                    :meta="nomenclaturesMeta"
                    :filters="nomenclatureFilters"
                />
            </template>
        </div>
    </SubscriberLayout>
</template>