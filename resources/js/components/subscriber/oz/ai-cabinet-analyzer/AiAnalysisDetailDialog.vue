<script setup>
import { computed, ref, watch } from "vue";
import {
    AlertTriangle,
    Download,
    Lightbulb,
    ListChecks,
    RefreshCw,
    Sparkles,
} from "lucide-vue-next";
import Alert from "@/components/ui/Alert.vue";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
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
const hasFeedContent = computed(
    () => insightsRows.value.length || risksRows.value.length || actionsRows.value.length,
);

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

        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.content ?? "";
            const response = await fetch(`/panel/oz/ai-cabinet-analyzer/ai-analyses/${analysisId}`, {
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": token,
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });

            const contentType = response.headers.get("content-type") || "";
            if (!response.ok) {
                const message = response.status === 403
                    ? "Нет доступа к этому анализу"
                    : response.status === 404
                        ? "Анализ не найден"
                        : `Не удалось загрузить анализ (код ${response.status})`;
                error.value = message;
                showError(message);
                return;
            }

            if (!contentType.includes("application/json")) {
                error.value = "Не удалось загрузить анализ: сервер вернул неожиданный ответ";
                showError(error.value);
                return;
            }

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
        class="max-w-5xl"
        :title="displayAnalysis?.template?.name || 'ИИ-анализ'"
        @update:open="emit('update:open', $event)"
    >
        <!-- Meta header -->
        <div
            v-if="displayAnalysis"
            class="mb-5 flex flex-wrap items-center gap-x-4 gap-y-2 border-b pb-4 text-sm"
        >
            <Badge :variant="analysisStatusVariant(displayAnalysis.status)">
                {{ analysisStatusLabel(displayAnalysis.status) }}
            </Badge>
            <span class="text-muted-foreground">
                Создан:
                <span class="font-medium text-foreground">
                    {{ formatAnalysisDateTime(displayAnalysis.created_at) }}
                </span>
            </span>
            <span v-if="displayAnalysis.finished_at" class="text-muted-foreground">
                Завершён:
                <span class="font-medium text-foreground">
                    {{ formatAnalysisDateTime(displayAnalysis.finished_at) }}
                </span>
            </span>
            <span v-if="displayAnalysis.total_tokens" class="text-muted-foreground">
                Токены:
                <span class="font-medium tabular-nums text-foreground">
                    {{ formatTokenCount(displayAnalysis.total_tokens) }}
                </span>
            </span>
        </div>

        <div class="max-h-[calc(90dvh-14rem)] overflow-y-auto overscroll-contain pr-1">
            <div class="space-y-6">
                <!-- Loading -->
                <div v-if="loading" class="space-y-4">
                    <Skeleton class="h-28 w-full rounded-2xl" />
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Skeleton class="h-24 rounded-xl" />
                        <Skeleton class="h-24 rounded-xl" />
                        <Skeleton class="h-24 rounded-xl" />
                        <Skeleton class="h-24 rounded-xl" />
                    </div>
                    <Skeleton class="h-20 w-full rounded-xl" />
                    <Skeleton class="h-20 w-full rounded-xl" />
                </div>

                <p v-else-if="error" class="text-sm text-muted-foreground">
                    Не удалось загрузить анализ
                </p>

                <template v-else-if="displayAnalysis">
                    <Alert v-if="displayAnalysis.status === 'processing'">
                        Анализ выполняется. Результат появится после завершения обработки.
                    </Alert>

                    <!-- Markdown report -->
                    <div
                        v-else-if="isMarkdownAnalysis(displayAnalysis) && markdownHtml"
                        class="ai-analysis-markdown"
                        v-html="markdownHtml"
                    />

                    <Alert v-else-if="isMarkdownAnalysis(displayAnalysis) && !markdownHtml">
                        Markdown-отчёт пуст.
                    </Alert>

                    <!-- Structured report -->
                    <template v-else-if="structuredAnalysis">
                        <!-- Summary -->
                        <div
                            v-if="structuredAnalysis.summary"
                            class="relative overflow-hidden rounded-2xl border border-primary/20 bg-primary/5 p-5 sm:p-6"
                        >
                            <div class="flex items-start gap-3">
                                <div
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/15 text-primary"
                                >
                                    <Sparkles class="h-5 w-5" />
                                </div>
                                <div class="min-w-0 space-y-2">
                                    <p class="text-xs font-semibold uppercase tracking-wider text-primary">
                                        Резюме
                                    </p>
                                    <p class="text-base font-medium leading-relaxed sm:text-lg">
                                        {{ structuredAnalysis.summary }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Metrics dashboard -->
                        <div v-if="metricsRows.length" class="space-y-3">
                            <h3 class="text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                                Метрики
                            </h3>
                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div
                                    v-for="metric in metricsRows"
                                    :key="metric.key"
                                    class="flex min-h-[6.5rem] flex-col justify-center rounded-2xl border bg-card p-4 transition-shadow hover:shadow-sm sm:p-5"
                                >
                                    <p class="text-2xl font-semibold tabular-nums tracking-tight sm:text-3xl">
                                        {{ metric.value }}
                                    </p>
                                    <p class="mt-1.5 text-xs text-muted-foreground sm:text-sm">
                                        {{ metric.shortLabel || metric.label }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Feed: insights / risks / actions -->
                        <template v-if="hasFeedContent">
                            <!-- Insights -->
                            <section v-if="insightsRows.length" class="space-y-3">
                                <div class="flex items-center gap-2">
                                    <Lightbulb class="h-4 w-4 text-primary" />
                                    <h3 class="text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                                        Инсайты
                                        <span class="ml-1 font-normal normal-case text-muted-foreground/80">
                                            ({{ insightsRows.length }})
                                        </span>
                                    </h3>
                                </div>
                                <div class="space-y-3">
                                    <div
                                        v-for="(item, index) in insightsRows"
                                        :key="`insight-${index}`"
                                        class="rounded-xl border border-l-4 border-l-primary bg-card p-4 transition-shadow hover:shadow-sm"
                                    >
                                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                            <p class="font-semibold">
                                                {{ item.title || `Инсайт #${index + 1}` }}
                                            </p>
                                            <Badge
                                                v-if="item.priority"
                                                :variant="priorityVariant(item.priority)"
                                            >
                                                {{ item.priority }}
                                            </Badge>
                                        </div>
                                        <p class="text-sm leading-relaxed text-muted-foreground">
                                            {{ item.description || "—" }}
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <!-- Risks -->
                            <section v-if="risksRows.length" class="space-y-3">
                                <div class="flex items-center gap-2">
                                    <AlertTriangle class="h-4 w-4 text-destructive" />
                                    <h3 class="text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                                        Риски
                                        <span class="ml-1 font-normal normal-case text-muted-foreground/80">
                                            ({{ risksRows.length }})
                                        </span>
                                    </h3>
                                </div>
                                <div class="space-y-3">
                                    <div
                                        v-for="(item, index) in risksRows"
                                        :key="`risk-${index}`"
                                        class="rounded-xl border border-l-4 border-l-destructive bg-card p-4 transition-shadow hover:shadow-sm"
                                    >
                                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                            <p class="font-semibold">
                                                {{ item.title || `Риск #${index + 1}` }}
                                            </p>
                                            <Badge
                                                v-if="item.priority"
                                                :variant="priorityVariant(item.priority)"
                                            >
                                                {{ item.priority }}
                                            </Badge>
                                        </div>
                                        <p class="text-sm leading-relaxed text-muted-foreground">
                                            {{ item.description || "—" }}
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <!-- Recommendations -->
                            <section v-if="actionsRows.length" class="space-y-3">
                                <div class="flex items-center gap-2">
                                    <ListChecks class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                                    <h3 class="text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                                        Рекомендации
                                        <span class="ml-1 font-normal normal-case text-muted-foreground/80">
                                            ({{ actionsRows.length }})
                                        </span>
                                    </h3>
                                </div>
                                <div class="space-y-3">
                                    <div
                                        v-for="(item, index) in actionsRows"
                                        :key="`action-${index}`"
                                        class="rounded-xl border border-l-4 border-l-emerald-600 bg-card p-4 transition-shadow hover:shadow-sm dark:border-l-emerald-500"
                                    >
                                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex items-start gap-2">
                                                <ListChecks
                                                    class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                                                />
                                                <p class="font-semibold">
                                                    {{ item.title || `Действие #${index + 1}` }}
                                                </p>
                                            </div>
                                            <Badge
                                                v-if="item.priority"
                                                :variant="priorityVariant(item.priority)"
                                            >
                                                {{ item.priority }}
                                            </Badge>
                                        </div>
                                        <p class="pl-6 text-sm leading-relaxed text-muted-foreground">
                                            {{ item.description || "—" }}
                                        </p>
                                    </div>
                                </div>
                            </section>
                        </template>
                    </template>

                    <!-- Raw fallback -->
                    <div
                        v-else-if="rawAnalysisText"
                        class="rounded-2xl border bg-muted/20 p-5"
                    >
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
                v-if="canRegenerateAnalysis(displayAnalysis)"
                variant="outline"
                :disabled="regenerating"
                @click="emit('regenerate', displayAnalysis)"
            >
                <RefreshCw class="mr-1.5 h-4 w-4" :class="{ 'animate-spin': regenerating }" />
                Перегенерировать
            </Button>
            <Button
                v-if="displayAnalysis?.status === 'done'"
                variant="outline"
                @click="emit('download', displayAnalysis)"
            >
                <Download class="mr-1.5 h-4 w-4" />
                PDF
            </Button>
            <Button @click="emit('update:open', false)">Закрыть</Button>
        </template>
    </Dialog>
</template>

<style scoped>
.ai-analysis-markdown {
    line-height: 1.7;
    font-size: 0.9375rem;
    color: hsl(var(--foreground) / 0.92);
    word-break: break-word;
}

.ai-analysis-markdown :deep(h1) {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 2rem 0 0.875rem;
    color: hsl(var(--foreground));
    letter-spacing: -0.02em;
}

.ai-analysis-markdown :deep(h2) {
    font-size: 1.25rem;
    font-weight: 700;
    margin: 1.75rem 0 0.75rem;
    color: hsl(var(--foreground));
    border-bottom: 1px solid hsl(var(--border));
    padding-bottom: 0.5rem;
    letter-spacing: -0.015em;
}

.ai-analysis-markdown :deep(h3) {
    font-size: 1.0625rem;
    font-weight: 600;
    margin: 1.5rem 0 0.625rem;
    color: hsl(var(--foreground));
}

.ai-analysis-markdown :deep(h4) {
    font-size: 1rem;
    font-weight: 600;
    margin: 1.25rem 0 0.5rem;
    color: hsl(var(--foreground));
}

.ai-analysis-markdown :deep(p) {
    margin-bottom: 0.875rem;
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
    margin: 0 0 0.875rem;
    padding-left: 1.35rem;
}

.ai-analysis-markdown :deep(li) {
    margin-bottom: 0.4rem;
}

.ai-analysis-markdown :deep(li::marker) {
    color: hsl(var(--primary));
}

.ai-analysis-markdown :deep(blockquote) {
    border-left: 3px solid hsl(var(--primary));
    margin: 1.25rem 0;
    padding: 0.875rem 1.125rem;
    background: hsl(var(--primary) / 0.05);
    border-radius: 0 0.75rem 0.75rem 0;
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
    border-radius: 0.75rem;
    padding: 1rem;
    margin: 1.25rem 0;
    overflow-x: auto;
}

.ai-analysis-markdown :deep(pre code) {
    background: none;
    padding: 0;
}

.ai-analysis-markdown :deep(img) {
    max-width: 100%;
    height: auto;
    border-radius: 0.75rem;
    margin: 1.25rem 0;
}

.ai-analysis-markdown :deep(hr) {
    border: none;
    border-top: 1px solid hsl(var(--border));
    margin: 1.75rem 0;
}

.ai-analysis-markdown :deep(table) {
    width: 100%;
    border-collapse: collapse;
    margin: 1.25rem 0;
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
