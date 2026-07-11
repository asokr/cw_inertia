<script setup>
import { computed, ref, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import ItemsTable from "@/components/subscriber/oz/price-calc/ItemsTable.vue";
import WorkflowAlert from "@/components/subscriber/oz/price-calc/WorkflowAlert.vue";
import JobProgress from "@/components/subscriber/oz/price-calc/JobProgress.vue";
import ModeToggle from "@/components/subscriber/oz/price-calc/ModeToggle.vue";
import ModeToolbar from "@/components/subscriber/oz/price-calc/ModeToolbar.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Card from "@/components/ui/Card.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useFlashToast } from "@/composables/useFlashToast";
import { useOzPriceCalcPoll } from "@/composables/useOzPriceCalcPoll";

const props = defineProps({
    cabinet: { type: Object, required: true },
    mode: { type: String, default: "fbo" },
    rows: { type: Array, default: () => [] },
    rowsMeta: { type: Object, default: () => ({}) },
    columns: { type: Array, default: () => [] },
    rowsError: { type: String, default: null },
    jobStatus: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Ценообразование Ozon", href: "/panel/oz/price-calc" },
    { label: props.cabinet.name },
];

const activeMode = ref(props.mode);
const baseUrl = `/panel/oz/price-calc/cabinets/${props.cabinet.id}`;

const exportDownloadUrl = computed(() => (
    activeMode.value === "fbs"
        ? `${baseUrl}/fbs/export-download`
        : `${baseUrl}/export-download`
));

useOzPriceCalcPoll(activeMode, exportDownloadUrl);

const { watchPropToast } = useFlashToast();
watchPropToast(() => props.rowsError);

const isBusy = computed(() => Boolean(
    props.jobStatus.is_syncing
    || props.jobStatus.is_calculating
    || props.jobStatus.is_importing
    || props.jobStatus.is_exporting,
));

watch(activeMode, (mode) => {
    router.get(
        baseUrl,
        {
            mode,
            page: 1,
            per_page: props.filters.per_page ?? 250,
            search: props.filters.search ?? "",
        },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
});
</script>

<template>
    <Head :title="`Ценообразование — ${cabinet.name}`" />

    <SubscriberLayout :title="cabinet.name" :breadcrumbs="breadcrumbs">
        <ToolPageHeader title="Ценообразование Ozon" :description="cabinet.name" />

        <div class="space-y-4">
            <Card class="p-4">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="space-y-1">
                        <p class="font-medium">{{ cabinet.name }}</p>
                        <p v-if="cabinet.client_id" class="text-sm text-muted-foreground">
                            Client ID: {{ cabinet.client_id }}
                        </p>
                    </div>
                    <ModeToggle v-model="activeMode" :disabled="isBusy" />
                </div>

                <div class="mt-4">
                    <ModeToolbar
                        :mode="activeMode"
                        :base-url="baseUrl"
                        :job-status="jobStatus"
                    />
                </div>
            </Card>

            <JobProgress :job-status="jobStatus" />

            <WorkflowAlert />

            <ItemsTable
                :mode="activeMode"
                :items="rows"
                :columns="columns"
                :rows-meta="rowsMeta"
                :filters="filters"
                :show-url="baseUrl"
            />
        </div>
    </SubscriberLayout>
</template>