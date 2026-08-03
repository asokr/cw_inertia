<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { CheckCircle2, Loader2, XCircle } from "lucide-vue-next";
import Card from "@/components/ui/Card.vue";
import { cn } from "@/lib/utils";

const props = defineProps({
    title: { type: String, default: "Выполняем задачу" },
    stages: {
        type: Array,
        default: () => [],
    },
    currentStage: { type: String, default: null },
    statusLabel: { type: String, default: null },
    progressPercent: { type: Number, default: null },
    detail: { type: String, default: null },
    waitingHint: { type: String, default: null },
    startedAt: { type: String, default: null },
    failed: { type: Boolean, default: false },
    error: { type: String, default: null },
    /** When true, show compact "done" state instead of hiding the bar. */
    completed: { type: Boolean, default: false },
});

const elapsedLabel = ref("");
let elapsedTimer = null;

const stageIndex = computed(() => {
    if (!props.currentStage || !props.stages.length) {
        return 0;
    }

    const index = props.stages.findIndex((stage) => stage.key === props.currentStage);
    return index >= 0 ? index : 0;
});

const stepNumber = computed(() => {
    if (!props.stages.length) {
        return 1;
    }

    return Math.min(stageIndex.value + 1, props.stages.length);
});

const stepsTotal = computed(() => Math.max(props.stages.length, 1));

const currentStageMeta = computed(() => props.stages[stageIndex.value] ?? null);

const resolvedProgressPercent = computed(() => {
    if (props.completed && !props.failed) {
        return 100;
    }

    if (typeof props.progressPercent === "number") {
        return Math.min(100, Math.max(0, props.progressPercent));
    }

    if (!props.stages.length) {
        return props.failed ? 0 : 8;
    }

    const completedStages = props.failed ? stageIndex.value : stageIndex.value + 0.35;
    return Math.min(100, Math.round((completedStages / props.stages.length) * 100));
});

const headline = computed(() => {
    if (props.failed) {
        return props.error || "Не удалось завершить задачу";
    }

    if (props.completed) {
        return props.statusLabel || "Готово";
    }

    return props.statusLabel || currentStageMeta.value?.label || props.title;
});

const stepLabel = computed(() => {
    if (props.failed || props.completed || !props.stages.length) {
        return null;
    }

    return `Шаг ${stepNumber.value} из ${stepsTotal.value}`;
});

function formatElapsed(startedAt) {
    if (!startedAt) {
        return "";
    }

    const started = new Date(startedAt);
    if (Number.isNaN(started.getTime())) {
        return "";
    }

    const seconds = Math.max(0, Math.floor((Date.now() - started.getTime()) / 1000));
    const minutes = Math.floor(seconds / 60);
    const restSeconds = seconds % 60;

    if (minutes > 0) {
        return `${minutes} мин ${restSeconds} сек`;
    }

    return `${restSeconds} сек`;
}

function refreshElapsed() {
    elapsedLabel.value = formatElapsed(props.startedAt);
}

watch(
    () => props.startedAt,
    () => refreshElapsed(),
);

onMounted(() => {
    refreshElapsed();
    elapsedTimer = window.setInterval(refreshElapsed, 1000);
});

onUnmounted(() => {
    if (elapsedTimer) {
        window.clearInterval(elapsedTimer);
    }
});
</script>

<template>
    <Card
        :class="cn(
            'border px-4 py-3 shadow-sm',
            failed && 'border-destructive/40 bg-destructive/5',
            completed && !failed && 'border-emerald-500/30 bg-emerald-500/5',
            !failed && !completed && 'border-primary/20 bg-primary/5',
        )"
        role="status"
        aria-live="polite"
    >
        <div class="flex min-w-0 items-center gap-3">
            <div class="flex h-8 w-8 shrink-0 items-center justify-center">
                <XCircle v-if="failed" class="h-5 w-5 text-destructive" aria-hidden="true" />
                <CheckCircle2
                    v-else-if="completed"
                    class="h-5 w-5 text-emerald-600"
                    aria-hidden="true"
                />
                <Loader2 v-else class="h-5 w-5 animate-spin text-primary" aria-hidden="true" />
            </div>

            <div class="min-w-0 flex-1 space-y-1.5">
                <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <span
                        v-if="stepLabel"
                        class="shrink-0 text-xs font-medium tabular-nums text-muted-foreground"
                    >
                        {{ stepLabel }}
                    </span>
                    <span
                        :class="cn(
                            'min-w-0 truncate text-sm font-medium',
                            failed ? 'text-destructive' : 'text-foreground',
                        )"
                    >
                        {{ headline }}
                    </span>
                </div>

                <div
                    v-if="!failed"
                    class="h-1.5 overflow-hidden rounded-full bg-muted"
                >
                    <div
                        class="h-full rounded-full bg-primary transition-all duration-500 ease-out"
                        :class="{ 'animate-pulse': waitingHint && !completed }"
                        :style="{ width: `${resolvedProgressPercent}%` }"
                    />
                </div>

                <p
                    v-if="!failed && !completed"
                    class="text-xs text-muted-foreground"
                >
                    Страницу можно закрыть — расчёт продолжится. Вернётесь позже — увидите результат.
                </p>
                <p v-else-if="detail && !failed" class="text-xs text-muted-foreground">
                    {{ detail }}
                </p>
            </div>

            <div
                v-if="!failed && !completed && elapsedLabel"
                class="hidden shrink-0 text-xs tabular-nums text-muted-foreground sm:block"
            >
                {{ elapsedLabel }}
            </div>
        </div>
    </Card>
</template>
