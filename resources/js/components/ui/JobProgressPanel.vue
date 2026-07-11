<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import { Check, Circle, Loader2 } from "lucide-vue-next";
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
});

const elapsedLabel = ref("");
let elapsedTimer = null;

const stageIndex = computed(() => {
    if (!props.currentStage) {
        return 0;
    }

    const index = props.stages.findIndex((stage) => stage.key === props.currentStage);
    return index >= 0 ? index : 0;
});

const currentStageMeta = computed(() => props.stages[stageIndex.value] ?? null);

const resolvedProgressPercent = computed(() => {
    if (typeof props.progressPercent === "number") {
        return Math.min(100, Math.max(0, props.progressPercent));
    }

    if (!props.stages.length) {
        return 0;
    }

    const completedStages = props.failed ? stageIndex.value : stageIndex.value + 0.35;
    return Math.min(100, Math.round((completedStages / props.stages.length) * 100));
});

const headline = computed(() => {
    if (props.failed) {
        return "Не удалось завершить задачу";
    }

    return props.statusLabel || props.title;
});

const subtitle = computed(() => {
    if (props.failed) {
        return null;
    }

    if (props.statusLabel && currentStageMeta.value?.description) {
        return currentStageMeta.value.description;
    }

    return currentStageMeta.value?.description ?? null;
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

function stageState(index) {
    if (props.failed) {
        if (index < stageIndex.value) {
            return "done";
        }

        if (index === stageIndex.value) {
            return "failed";
        }

        return "pending";
    }

    if (index < stageIndex.value) {
        return "done";
    }

    if (index === stageIndex.value) {
        return "active";
    }

    return "pending";
}

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
            'overflow-hidden border-l-4 p-5 shadow-md',
            failed ? 'border-l-destructive bg-destructive/5' : 'border-l-primary bg-primary/5',
        )"
        role="status"
        aria-live="polite"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <h3 class="text-lg font-semibold tracking-tight text-foreground">
                    {{ headline }}
                </h3>
                <p v-if="!failed && subtitle" class="text-sm text-muted-foreground">
                    {{ subtitle }}
                </p>
                <p v-if="failed && error" class="text-sm text-destructive">
                    {{ error }}
                </p>
            </div>

            <div class="flex items-center gap-2">
                <div
                    v-if="!failed"
                    class="rounded-full border bg-background/80 px-3 py-1 text-xs font-semibold tabular-nums text-foreground"
                >
                    {{ resolvedProgressPercent }}%
                </div>
                <div
                    v-if="!failed && elapsedLabel"
                    class="rounded-full border bg-background/80 px-3 py-1 text-xs font-medium text-muted-foreground"
                >
                    Прошло: {{ elapsedLabel }}
                </div>
            </div>
        </div>

        <div v-if="!failed" class="mt-4 space-y-2">
            <div class="h-2 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full rounded-full bg-primary transition-all duration-700 ease-out"
                    :class="{ 'animate-pulse': waitingHint }"
                    :style="{ width: `${resolvedProgressPercent}%` }"
                />
            </div>
            <p class="text-xs text-muted-foreground">
                Сервис работает — страницу можно не закрывать, статус обновляется автоматически.
            </p>
        </div>

        <ol class="mt-5 space-y-3">
            <li
                v-for="(stage, index) in stages"
                :key="stage.key"
                class="flex items-start gap-3"
            >
                <div class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center">
                    <Check
                        v-if="stageState(index) === 'done'"
                        class="h-4 w-4 text-emerald-600"
                        aria-hidden="true"
                    />
                    <Loader2
                        v-else-if="stageState(index) === 'active'"
                        class="h-4 w-4 animate-spin text-primary"
                        aria-hidden="true"
                    />
                    <Circle
                        v-else
                        :class="cn(
                            'h-3 w-3',
                            stageState(index) === 'failed' ? 'text-destructive' : 'text-muted-foreground/50',
                        )"
                        aria-hidden="true"
                    />
                </div>

                <div class="min-w-0 flex-1">
                    <p
                        :class="cn(
                            'text-sm font-medium',
                            stageState(index) === 'active' && 'text-foreground',
                            stageState(index) === 'done' && 'text-muted-foreground',
                            stageState(index) === 'pending' && 'text-muted-foreground/70',
                            stageState(index) === 'failed' && 'text-destructive',
                        )"
                    >
                        {{ stage.label }}
                    </p>
                </div>
            </li>
        </ol>

        <div v-if="!failed && (detail || waitingHint)" class="mt-4 space-y-2 rounded-lg border bg-background/80 p-3 text-sm">
            <p v-if="detail" class="font-medium text-foreground">
                {{ detail }}
            </p>
            <p v-if="waitingHint" class="text-muted-foreground">
                {{ waitingHint }}
            </p>
        </div>
    </Card>
</template>