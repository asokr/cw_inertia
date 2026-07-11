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
    groups: { type: Array, default: () => [] },
    widget: { type: Object, default: null },
    exportUrl: { type: String, required: true },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Рентабельность Wildberries", href: "/panel/wb/profitability" },
    { label: props.cabinet.name },
];

const OPERATIONS = {
    logistics: "Логистика",
    returns: "Возврат",
    sales: "Продажа",
    storage: "Хранение",
    penalty: "Штраф",
    acceptance: "Платная приемка",
    withholdings: "Удержание",
    logistics_correction: "Коррекция логистики",
};

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

function asItemList(items) {
    if (Array.isArray(items)) {
        return items;
    }

    if (items && typeof items === "object") {
        return Object.values(items);
    }

    return [];
}

function normalizeGroup(group, fallbackName = "") {
    if (!group || typeof group !== "object") {
        return { supplier_oper_name: fallbackName, items: [] };
    }

    if ("supplier_oper_name" in group && "items" in group) {
        return {
            supplier_oper_name: String(group.supplier_oper_name ?? fallbackName),
            items: asItemList(group.items),
        };
    }

    return {
        supplier_oper_name: String(group.supplier_oper_name ?? fallbackName),
        items: asItemList(group),
    };
}

function normalizeGroups(groups) {
    if (Array.isArray(groups)) {
        return groups.map((group) => normalizeGroup(group));
    }

    if (groups && typeof groups === "object") {
        return Object.entries(groups).map(([key, group]) => normalizeGroup(group, key));
    }

    return [];
}

const groupsList = computed(() => normalizeGroups(props.groups));

function findGroup(name) {
    const group = groupsList.value.find((entry) => entry.supplier_oper_name === name);
    return asItemList(group?.items);
}

const sales = computed(() => findGroup(OPERATIONS.sales));
const returns = computed(() => findGroup(OPERATIONS.returns));
const logistics = computed(() => findGroup(OPERATIONS.logistics));

const storage = computed(() => {
    const group = groupsList.value.find((entry) => entry.supplier_oper_name === OPERATIONS.storage);
    if (!group) return [];

    let sum = 0;
    asItemList(group.items).forEach((item) => {
        sum += Number(Math.round(item.sum_to_transfer));
    });

    return [{ sum_to_transfer: sum }];
});

const penalty = computed(() => findGroup(OPERATIONS.penalty));
const acceptance = computed(() => findGroup(OPERATIONS.acceptance));
const withholdings = computed(() => findGroup(OPERATIONS.withholdings));
const logisticsCorrection = computed(() => findGroup(OPERATIONS.logistics_correction));

const allItems = computed(() => {
    const withType = (items, type) => asItemList(items).map((item) => ({ ...item, type }));

    return [
        ...withType(penalty.value, "Штрафы"),
        ...withType(acceptance.value, "Платная приемка"),
        ...withType(withholdings.value, "Удержание"),
        ...withType(logisticsCorrection.value, "Коррекция логистики"),
        ...withType(storage.value, "Хранение"),
    ];
});

const showReportDetails = computed(() => hasReport.value);
const hasSalesItems = computed(() => sales.value.length > 0);
const hasReturnsItems = computed(() => returns.value.length > 0);
const hasLogisticsItems = computed(() => logistics.value.length > 0);
const hasOtherItems = computed(() => allItems.value.length > 0);

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

            <template v-if="showReportDetails">
                <ReportTotals :report="report" :export-url="exportUrl" />

                <SalesTable v-if="hasSalesItems" :items="sales" />
                <ReturnsTable v-if="hasReturnsItems" :items="returns" />
                <LogisticsTable v-if="hasLogisticsItems" :items="logistics" />
                <OtherOperationsTable v-if="hasOtherItems" :items="allItems" />
            </template>
        </div>
    </SubscriberLayout>
</template>