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
        <div v-if="displayAnalysis" class="mb-3 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
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

        <div class="max-h-[calc(90dvh-14rem)] overflow-y-auto overscroll-contain pr-1">
            <div class="space-y-4">
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
                        class="ai-analysis-markdown"
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

                    <div v-else-if="rawAnalysisText" class="rounded-md border bg-muted/20 p-4">
                        <pre class="whitespace-pre-wrap text-sm leading-relaxed">{{ rawAnalysisText }}</pre>
                    </div>

                    <p
                        v-else-if="displayAnalysis.status !== 'failed' && displayAnalysis.status !== 'processing'"
                        class="text-sm text-muted-foreground"
                    >
                        Данные анализа ещё не получены или не поддерживаются.
                    </p>
                </template>
            </div>
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

<style scoped>
.ai-analysis-markdown {
    line-height: 1.65;
    font-size: 0.875rem;
    color: hsl(var(--foreground) / 0.9);
    word-break: break-word;
}

.ai-analysis-markdown :deep(h1) {
    font-size: 1.375rem;
    font-weight: 700;
    margin: 1.75rem 0 0.75rem;
    color: hsl(var(--foreground));
}

.ai-analysis-markdown :deep(h2) {
    font-size: 1.125rem;
    font-weight: 700;
    margin: 1.5rem 0 0.625rem;
    color: hsl(var(--foreground));
    border-bottom: 1px solid hsl(var(--border));
    padding-bottom: 0.375rem;
}

.ai-analysis-markdown :deep(h3) {
    font-size: 1rem;
    font-weight: 600;
    margin: 1.25rem 0 0.5rem;
    color: hsl(var(--foreground));
}

.ai-analysis-markdown :deep(h4) {
    font-size: 0.9375rem;
    font-weight: 600;
    margin: 1rem 0 0.375rem;
    color: hsl(var(--foreground));
}

.ai-analysis-markdown :deep(p) {
    margin-bottom: 0.75rem;
}

.ai-analysis-markdown :deep(p:last-child) {
    margin-bottom: 0;
}

.ai-analysis-markdown :deep(a) {
    color: hsl(var(--primary));
    text-decoration: underline;
    text-underline-offset: 3px;
}

.ai-analysis-markdown :deep(a:hover) {
    opacity: 0.8;
}

.ai-analysis-markdown :deep(strong) {
    color: hsl(var(--foreground));
    font-weight: 600;
}

.ai-analysis-markdown :deep(ul),
.ai-analysis-markdown :deep(ol) {
    margin: 0 0 0.75rem;
    padding-left: 1.25rem;
}

.ai-analysis-markdown :deep(li) {
    margin-bottom: 0.375rem;
}

.ai-analysis-markdown :deep(li::marker) {
    color: hsl(var(--primary));
}

.ai-analysis-markdown :deep(blockquote) {
    border-left: 3px solid hsl(var(--primary));
    margin: 1rem 0;
    padding: 0.75rem 1rem;
    background: hsl(var(--primary) / 0.05);
    border-radius: 0 0.5rem 0.5rem 0;
    color: hsl(var(--muted-foreground));
}

.ai-analysis-markdown :deep(code) {
    background: hsl(var(--muted));
    border-radius: 0.25rem;
    padding: 0.125rem 0.375rem;
    font-size: 0.8125rem;
    color: hsl(var(--foreground));
}

.ai-analysis-markdown :deep(pre) {
    background: hsl(var(--muted));
    border: 1px solid hsl(var(--border));
    border-radius: 0.5rem;
    padding: 0.875rem;
    margin: 1rem 0;
    overflow-x: auto;
}

.ai-analysis-markdown :deep(pre code) {
    background: none;
    padding: 0;
}

.ai-analysis-markdown :deep(img) {
    max-width: 100%;
    height: auto;
    border-radius: 0.5rem;
    margin: 1rem 0;
}

.ai-analysis-markdown :deep(hr) {
    border: none;
    border-top: 1px solid hsl(var(--border));
    margin: 1.5rem 0;
}

.ai-analysis-markdown :deep(table) {
    width: 100%;
    border-collapse: collapse;
    margin: 1rem 0;
    font-size: 0.8125rem;
    display: block;
    overflow-x: auto;
}

.ai-analysis-markdown :deep(th),
.ai-analysis-markdown :deep(td) {
    border: 1px solid hsl(var(--border));
    padding: 0.5rem 0.75rem;
    text-align: left;
    vertical-align: top;
}

.ai-analysis-markdown :deep(th) {
    background: hsl(var(--muted));
    font-weight: 600;
}

.ai-analysis-markdown :deep(> :first-child) {
    margin-top: 0;
}
</style>