<script setup>
import { computed, unref } from "vue";
import { Head } from "@inertiajs/vue3";
import AiAnalysisSection from "@/components/subscriber/oz/ai-cabinet-analyzer/AiAnalysisSection.vue";
import ProductsTable from "@/components/subscriber/oz/ai-cabinet-analyzer/ProductsTable.vue";
import ReportRunPanel from "@/components/subscriber/oz/ai-cabinet-analyzer/ReportRunPanel.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useAiCabinetReportPoll } from "@/composables/useAiCabinetReportPoll";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    cabinet: { type: Object, required: true },
    report: { type: Object, default: null },
    meta: { type: Object, default: null },
    products: { type: Array, default: () => [] },
    productsMeta: { type: Object, default: () => ({}) },
    productFilters: { type: Object, default: () => ({}) },
    templates: { type: Array, default: () => [] },
    analyses: { type: Array, default: () => [] },
    analysesMeta: { type: Object, default: () => ({}) },
    defaultPeriod: { type: Object, default: () => ({}) },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "ИИ анализ кабинета Ozon", href: "/panel/oz/ai-cabinet-analyzer" },
    { label: props.cabinet.name },
];

const { showError, watchPropToast } = useFlashToast();

const poll = useAiCabinetReportPoll({
    onFailed: (message) => {
        showError(message);
    },
});

const isReportPolling = computed(() => Boolean(unref(poll.isPolling)));
const isReportLongRunning = computed(() => Boolean(unref(poll.timedOut)));

const showUrl = computed(() => `/panel/oz/ai-cabinet-analyzer`);
const startUrl = computed(() => `${showUrl.value}/reports`);
const isReportDone = computed(() => props.report?.status === "done");
const hasMeta = computed(() => Boolean(props.meta && Object.keys(props.meta).length > 0));
const warnings = computed(() => (Array.isArray(props.meta?.warnings) ? props.meta.warnings : []));
const productsCount = computed(() => props.meta?.products_count ?? props.productsMeta?.total ?? null);

function onPollingStart() {
    poll.start();
}

watchPropToast(() => warnings.value, "default");
</script>

<template>
    <Head :title="`ИИ анализ кабинета Ozon — ${cabinet.name}`" />

    <SubscriberLayout :title="cabinet.name" :breadcrumbs="breadcrumbs">
        <ToolPageHeader
            title="Анализ кабинета Ozon"
            :description="cabinet.name"
        />

        <div class="space-y-8 md:space-y-10">
            <ReportRunPanel
                :report="report"
                :default-period="defaultPeriod"
                :is-polling="isReportPolling"
                :timed-out="isReportLongRunning"
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
                <div v-if="isReportDone" class="space-y-3">
                    <p v-if="productsCount !== null" class="text-sm text-muted-foreground">
                        Товаров в snapshot: <span class="font-medium text-foreground">{{ productsCount }}</span>
                    </p>
                    <ProductsTable
                        :show-url="showUrl"
                        :report-id="report?.id"
                        :items="products"
                        :meta="productsMeta"
                        :filters="productFilters"
                    />
                </div>
            </template>
        </div>
    </SubscriberLayout>
</template>
