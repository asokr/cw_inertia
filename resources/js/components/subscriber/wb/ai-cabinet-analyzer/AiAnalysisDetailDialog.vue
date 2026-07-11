<script setup>
import { computed, ref, watch } from "vue";
import { Download, RefreshCw } from "lucide-vue-next";
import Alert from "@/components/ui/Alert.vue";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import Tabs from "@/components/ui/Tabs.vue";
import TabsContent from "@/components/ui/TabsContent.vue";
import TabsList from "@/components/ui/TabsList.vue";
import TabsTrigger from "@/components/ui/TabsTrigger.vue";
import {
    analysisStatusLabel,
    analysisStatusVariant,
    buildMetricsRows,
    canRegenerateAnalysis,
    formatAnalysisDateTime,
    formatTokenCount,
    getMarkdownSource,
    isMarkdownAnalysis,
    normalizeAnalysisRows,
    parseRawAnalysisText,
    parseStructuredAnalysis,
    priorityVariant,
} from "@/utils/aiCabinetAnalysisDisplay";
import { renderBlogMarkdown } from "@/utils/renderBlogMarkdown";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    open: Boolean,
    analysisId: { type: [Number, String, null], default: null },
    analysisSummary: { type: Object, default: null },
    regenerating: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "loaded", "regenerate", "download"]);

const loading = ref(false);
const error = ref(null);
const analysis = ref(null);
const { showError } = useFlashToast();
const activeTab = ref("insights");

const displayAnalysis = computed(() => analysis.value ?? props.analysisSummary);
const structuredAnalysis = computed(() => parseStructuredAnalysis(displayAnalysis.value));
const rawAnalysisText = computed(() => parseRawAnalysisText(displayAnalysis.value));
const markdownHtml = computed(() => {
    if (!isMarkdownAnalysis(displayAnalysis.value)) return "";
    return renderBlogMarkdown(getMarkdownSource(displayAnalysis.value));
});
const metricsRows = computed(() => buildMetricsRows(structuredAnalysis.value));
const insightsRows = computed(() => normalizeAnalysisRows(structuredAnalysis.value?.insights));
const risksRows = computed(() => normalizeAnalysisRows(structuredAnalysis.value?.risks));
const actionsRows = computed(() => normalizeAnalysisRows(structuredAnalysis.value?.actions));
const hasTabbedContent = computed(() => insightsRows.value.length || risksRows.value.length || actionsRows.value.length);

const defaultTab = computed(() => {
    if (insightsRows.value.length) return "insights";
    if (risksRows.value.length) return "risks";
    if (actionsRows.value.length) return "actions";
    return "insights";
});

watch(defaultTab, (value) => {
    if (!activeTab.value || activeTab.value === "insights") {
        activeTab.value = value;
    }
});

watch(
    () => [props.open, props.analysisId],
    async ([isOpen, analysisId]) => {
        if (!isOpen || !analysisId) {
            analysis.value = null;
            error.value = null;
            return;
        }

        loading.value = true;
        error.value = null;
        analysis.value = null;
        activeTab.value = defaultTab.value;

        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.content ?? "";
            const response = await fetch(`/panel/wb/ai-cabinet-analyzer/ai-analyses/${analysisId}`, {
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": token,
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });

            const payload = await response.json();

            if (payload?.success) {
                analysis.value = payload.data ?? null;
                emit("loaded", analysis.value);
            } else {
                const message = Array.isArray(payload?.messages)
                    ? payload.messages.join(" ")
                    : "Не удалось загрузить анализ";
                error.value = message;
                showError(message);
            }
        } catch {
            error.value = "Не удалось загрузить анализ";
            showError("Не удалось загрузить анализ");
        } finally {
            loading.value = false;
        }
    },
);

watch(
    () => displayAnalysis.value?.status,
    (status) => {
        if (status === "failed") {
            showError(displayAnalysis.value?.error_message || "Неизвестная ошибка при выполнении ИИ-анализа");
        }
    },
    { immediate: true },
);
</script>

<template>
    <Dialog
        :open="open"
        class="max-w-4xl"
        :title="displayAnalysis?.template?.name || 'ИИ-анализ'"
        @update:open="emit('update:open', $event)"
    >
        <div class="space-y-4">
            <div v-if="displayAnalysis" class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                <Badge :variant="analysisStatusVariant(displayAnalysis.status)">
                    {{ analysisStatusLabel(displayAnalysis.status) }}
                </Badge>
                <span>Создан: {{ formatAnalysisDateTime(displayAnalysis.created_at) }}</span>
                <span v-if="displayAnalysis.finished_at">
                    Завершён: {{ formatAnalysisDateTime(displayAnalysis.finished_at) }}
                </span>
                <span v-if="displayAnalysis.total_tokens">
                    Токены: {{ formatTokenCount(displayAnalysis.total_tokens) }}
                </span>
            </div>

            <div v-if="loading" class="space-y-3">
                <Skeleton class="h-6 w-1/2" />
                <Skeleton class="h-24 w-full" />
                <Skeleton class="h-24 w-full" />
            </div>

            <p v-else-if="error" class="text-sm text-muted-foreground">Не удалось загрузить анализ</p>

            <template v-else-if="displayAnalysis">
                <Alert v-if="displayAnalysis.status === 'processing'">
                    Анализ выполняется. Результат появится после завершения обработки.
                </Alert>

                <div
                    v-else-if="isMarkdownAnalysis(displayAnalysis) && markdownHtml"
                    class="prose prose-sm max-w-none dark:prose-invert"
                    v-html="markdownHtml"
                />

                <Alert v-else-if="isMarkdownAnalysis(displayAnalysis) && !markdownHtml">
                    Markdown-отчёт пуст.
                </Alert>

                <template v-else-if="structuredAnalysis">
                    <div v-if="structuredAnalysis.summary" class="rounded-md border-l-4 border-primary bg-muted/30 p-4">
                        <p class="mb-1 text-sm font-semibold">Краткое резюме</p>
                        <p class="text-sm leading-relaxed">{{ structuredAnalysis.summary }}</p>
                    </div>

                    <div v-if="metricsRows.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="metric in metricsRows"
                            :key="metric.key"
                            class="rounded-md border p-3"
                        >
                            <p class="text-xs text-muted-foreground">{{ metric.label }}</p>
                            <p class="text-lg font-semibold">{{ metric.value }}</p>
                        </div>
                    </div>

                    <Tabs v-if="hasTabbedContent" v-model="activeTab">
                        <TabsList>
                            <TabsTrigger v-if="insightsRows.length" value="insights">
                                Инсайты ({{ insightsRows.length }})
                            </TabsTrigger>
                            <TabsTrigger v-if="risksRows.length" value="risks">
                                Риски ({{ risksRows.length }})
                            </TabsTrigger>
                            <TabsTrigger v-if="actionsRows.length" value="actions">
                                Рекомендации ({{ actionsRows.length }})
                            </TabsTrigger>
                        </TabsList>

                        <TabsContent value="insights">
                            <div class="space-y-3">
                                <div
                                    v-for="(item, index) in insightsRows"
                                    :key="`insight-${index}`"
                                    class="rounded-md border border-l-4 border-l-primary p-3"
                                >
                                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-sm font-semibold">{{ item.title || `Инсайт #${index + 1}` }}</p>
                                        <Badge :variant="priorityVariant(item.priority)">{{ item.priority || "—" }}</Badge>
                                    </div>
                                    <p class="text-sm text-muted-foreground">{{ item.description || "—" }}</p>
                                </div>
                            </div>
                        </TabsContent>

                        <TabsContent value="risks">
                            <div class="space-y-3">
                                <div
                                    v-for="(item, index) in risksRows"
                                    :key="`risk-${index}`"
                                    class="rounded-md border border-l-4 border-l-destructive p-3"
                                >
                                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-sm font-semibold">{{ item.title || `Риск #${index + 1}` }}</p>
                                        <Badge :variant="priorityVariant(item.priority)">{{ item.priority || "—" }}</Badge>
                                    </div>
                                    <p class="text-sm text-muted-foreground">{{ item.description || "—" }}</p>
                                </div>
                            </div>
                        </TabsContent>

                        <TabsContent value="actions">
                            <div class="space-y-3">
                                <div
                                    v-for="(item, index) in actionsRows"
                                    :key="`action-${index}`"
                                    class="rounded-md border border-l-4 border-l-green-600 p-3"
                                >
                                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-sm font-semibold">{{ item.title || `Действие #${index + 1}` }}</p>
                                        <Badge :variant="priorityVariant(item.priority)">{{ item.priority || "—" }}</Badge>
                                    </div>
                                    <p class="text-sm text-muted-foreground">{{ item.description || "—" }}</p>
                                </div>
                            </div>
                        </TabsContent>
                    </Tabs>
                </template>

                <div v-else-if="rawAnalysisText" class="max-h-96 overflow-auto rounded-md border bg-muted/20 p-4">
                    <pre class="whitespace-pre-wrap text-sm">{{ rawAnalysisText }}</pre>
                </div>

                <p
                    v-else-if="displayAnalysis.status !== 'failed' && displayAnalysis.status !== 'processing'"
                    class="text-sm text-muted-foreground"
                >
                    Данные анализа ещё не получены или не поддерживаются.
                </p>
            </template>
        </div>

        <template #footer>
            <Button
                v-if="displayAnalysis?.status === 'done'"
                @click="emit('download', displayAnalysis)"
            >
                <Download class="mr-1 h-4 w-4" />
                Скачать PDF
            </Button>
            <Button
                v-if="canRegenerateAnalysis(displayAnalysis)"
                variant="outline"
                :disabled="regenerating"
                @click="emit('regenerate', displayAnalysis)"
            >
                <RefreshCw class="mr-1 h-4 w-4" :class="{ 'animate-spin': regenerating }" />
                Перегенерировать
            </Button>
            <Button variant="outline" @click="emit('update:open', false)">Закрыть</Button>
        </template>
    </Dialog>
</template>