<script setup>
import { computed, ref, watch } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import AiAnalysesHistoryTable from "@/components/subscriber/wb/ai-cabinet-analyzer/AiAnalysesHistoryTable.vue";
import AiAnalysisDetailDialog from "@/components/subscriber/wb/ai-cabinet-analyzer/AiAnalysisDetailDialog.vue";
import AiAnalysisLauncher from "@/components/subscriber/wb/ai-cabinet-analyzer/AiAnalysisLauncher.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";
import { useAiCabinetAnalysesPoll } from "@/composables/useAiCabinetAnalysesPoll";

const props = defineProps({
    report: { type: Object, default: null },
    templates: { type: Array, default: () => [] },
    analyses: { type: Array, default: () => [] },
    analysesMeta: { type: Object, default: () => ({}) },
    showUrl: { type: String, required: true },
});

const selectedTemplateId = ref(props.templates[0]?.id ?? null);
const detailOpen = ref(false);
const detailAnalysis = ref(null);
const confirmRegenerateOpen = ref(false);
const regenerateTarget = ref(null);
const regeneratingId = ref(null);

const startForm = useForm({
    report_id: null,
    template_id: null,
});

const regenerateForm = useForm({});

const analysesPoll = useAiCabinetAnalysesPoll();

const isReportReady = computed(() => Boolean(props.report?.id) && props.report?.status === "done");

watch(
    () => props.templates,
    (items) => {
        if (!selectedTemplateId.value && items.length) {
            selectedTemplateId.value = items[0].id;
        }
    },
    { immediate: true },
);

watch(
    () => [props.report?.id, props.report?.status],
    ([reportId, status]) => {
        if (!reportId || status !== "done") {
            detailOpen.value = false;
            detailAnalysis.value = null;
            confirmRegenerateOpen.value = false;
            regenerateTarget.value = null;
        }
    },
);

function refreshAnalyses() {
    if (!isReportReady.value) return;

    router.get(props.showUrl, {
        report_id: props.report.id,
    }, {
        only: ["analyses", "analysesMeta"],
        preserveState: true,
        preserveScroll: true,
    });
}

function startAnalysis() {
    if (!isReportReady.value || !selectedTemplateId.value) return;

    startForm.report_id = props.report.id;
    startForm.template_id = selectedTemplateId.value;

    startForm.post("/panel/wb/ai-cabinet-analyzer/ai-analyses/start", {
        preserveScroll: true,
        onSuccess: () => {
            analysesPoll.start();
        },
    });
}

function requestRegenerate(row) {
    if (!row?.id || row.status === "processing") return;
    regenerateTarget.value = row;
    confirmRegenerateOpen.value = true;
}

function closeRegenerateConfirm() {
    confirmRegenerateOpen.value = false;
    regenerateTarget.value = null;
}

function confirmRegenerate() {
    if (!regenerateTarget.value?.id) return;

    const target = regenerateTarget.value;
    regeneratingId.value = target.id;

    regenerateForm.post(`/panel/wb/ai-cabinet-analyzer/ai-analyses/${target.id}/regenerate`, {
        preserveScroll: true,
        onFinish: () => {
            regeneratingId.value = null;
        },
        onSuccess: () => {
            confirmRegenerateOpen.value = false;
            regenerateTarget.value = null;
            analysesPoll.start();

            if (detailOpen.value && Number(detailAnalysis.value?.id) === Number(target.id)) {
                openAnalysis(target);
            }
        },
    });
}

function openAnalysis(row) {
    if (!row?.id || row.status === "processing") return;
    detailAnalysis.value = { id: row.id, template: row.template, status: row.status };
    detailOpen.value = true;
}

function onDetailLoaded(analysis) {
    detailAnalysis.value = analysis;
}

function downloadAnalysis(row) {
    if (!row?.id) return;
    window.location.href = `/panel/wb/ai-cabinet-analyzer/ai-analyses/${row.id}/download`;
}
</script>

<template>
    <Card class="overflow-hidden">
        <div class="space-y-6 p-4">
            <AiAnalysisLauncher
                :is-report-ready="isReportReady"
                :report-status="report?.status"
                :templates="templates"
                :selected-template-id="selectedTemplateId"
                :processing="startForm.processing"
                @update:selected-template-id="selectedTemplateId = $event"
                @start="startAnalysis"
            />

            <AiAnalysesHistoryTable
                :items="analyses"
                :polling="analysesPoll.isPolling"
                :regenerating-id="regeneratingId"
                @refresh="refreshAnalyses"
                @open="openAnalysis"
                @regenerate="requestRegenerate"
                @download="downloadAnalysis"
            />
        </div>
    </Card>

    <AiAnalysisDetailDialog
        v-model:open="detailOpen"
        :analysis-id="detailAnalysis?.id"
        :analysis-summary="detailAnalysis"
        :regenerating="Boolean(regeneratingId)"
        @loaded="onDetailLoaded"
        @regenerate="requestRegenerate"
        @download="downloadAnalysis"
    />

    <Dialog :open="confirmRegenerateOpen" title="Перегенерировать анализ?" @update:open="confirmRegenerateOpen = $event">
        <p class="text-sm">
            Будет обновлён текущий анализ
            <strong>{{ regenerateTarget?.template?.name || "без названия" }}</strong>.
            Предыдущий результат будет заменён новым.
        </p>
        <p class="mt-3 text-sm text-muted-foreground">
            Это может занять несколько минут. Статус обновится автоматически.
        </p>
        <template #footer>
            <Button variant="outline" @click="closeRegenerateConfirm">Отмена</Button>
            <Button :disabled="regenerateForm.processing" @click="confirmRegenerate">
                Перегенерировать
            </Button>
        </template>
    </Dialog>
</template>