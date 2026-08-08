<script setup>
import { computed, ref, unref, watch } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import AiAnalysesHistory from "@/components/subscriber/oz/ai-cabinet-analyzer/AiAnalysesHistory.vue";
import AiAnalysisDetailDialog from "@/components/subscriber/oz/ai-cabinet-analyzer/AiAnalysisDetailDialog.vue";
import AiAnalysisLauncher from "@/components/subscriber/oz/ai-cabinet-analyzer/AiAnalysisLauncher.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Separator from "@/components/ui/Separator.vue";
import { useAiCabinetAnalysesPoll } from "@/composables/useAiCabinetAnalysesPoll";

const props = defineProps({
    report: { type: Object, default: null },
    templates: { type: Array, default: () => [] },
    analyses: { type: Array, default: () => [] },
    analysesMeta: { type: Object, default: () => ({}) },
    showUrl: { type: String, required: true },
});

const detailOpen = ref(false);
const detailAnalysis = ref(null);
const confirmRegenerateOpen = ref(false);
const regenerateTarget = ref(null);
const regeneratingId = ref(null);
const launchingTemplateId = ref(null);

const startForm = useForm({
    report_id: null,
    template_id: null,
});

const regenerateForm = useForm({});

const analysesPoll = useAiCabinetAnalysesPoll();

// Nested refs from plain objects are not auto-unwrapped in templates
const isAnalysesPolling = computed(() => Boolean(unref(analysesPoll.isPolling)));

const isReportReady = computed(() => Boolean(props.report?.id) && props.report?.status === "done");

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

function startAnalysis(templateId) {
    if (!isReportReady.value || !templateId) return;

    const alreadyRunning = (props.analyses || []).some(
        (item) => item?.status === "processing" && Number(item?.template_id) === Number(templateId),
    );
    if (alreadyRunning) return;

    launchingTemplateId.value = templateId;
    startForm.report_id = props.report.id;
    startForm.template_id = templateId;

    startForm.post("/panel/oz/ai-cabinet-analyzer/ai-analyses/start", {
        preserveScroll: true,
        onFinish: () => {
            launchingTemplateId.value = null;
        },
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

    regenerateForm.post(`/panel/oz/ai-cabinet-analyzer/ai-analyses/${target.id}/regenerate`, {
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
    window.location.href = `/panel/oz/ai-cabinet-analyzer/ai-analyses/${row.id}/download`;
}
</script>

<template>
    <Card class="overflow-hidden">
        <div class="space-y-8 p-5 sm:p-6 md:p-8">
            <AiAnalysisLauncher
                :is-report-ready="isReportReady"
                :report-status="report?.status"
                :templates="templates"
                :analyses="analyses"
                :processing="startForm.processing"
                :launching-template-id="launchingTemplateId"
                @start="startAnalysis"
            />

            <Separator />

            <AiAnalysesHistory
                :items="analyses"
                :meta="analysesMeta"
                :polling="isAnalysesPolling"
                :regenerating-id="regeneratingId"
                :show-url="showUrl"
                :report-id="report?.id"
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

    <Dialog
        :open="confirmRegenerateOpen"
        title="Перегенерировать анализ?"
        @update:open="confirmRegenerateOpen = $event"
    >
        <p class="text-sm leading-relaxed">
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
