<script setup>
import { computed, ref, watch } from "vue";
import {
    Download,
    GripVertical,
    ImageOff,
    Replace,
    Trash2,
    Trophy,
} from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import { formatResultDelta, resultDeltaClass } from "./photoResultTone.js";

const props = defineProps({
    photo: {
        type: Object,
        required: true,
    },
    index: {
        type: Number,
        required: true,
    },
    /** Замена / DnD (draft, stopped, error). */
    editable: {
        type: Boolean,
        default: true,
    },
    /** Удаление варианта (в т.ч. running). */
    canDelete: {
        type: Boolean,
        default: true,
    },
    /** Скачивание файла — почти всегда true. */
    canDownload: {
        type: Boolean,
        default: true,
    },
    busy: {
        type: Boolean,
        default: false,
    },
    draggable: {
        type: Boolean,
        default: true,
    },
    /** Experiment status: draft | running | completed | stopped | error */
    experimentStatus: {
        type: String,
        default: "draft",
    },
    /** Текущий вариант на карточке Ozon (running). */
    isCurrent: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits([
    "replace",
    "delete",
    "download",
    "drag-start",
    "drag-over",
    "drop",
    "drag-end",
]);

const imgBroken = ref(false);
const replaceInput = ref(null);

watch(
    () => props.photo?.preview_url,
    () => {
        imgBroken.value = false;
    },
);

const stats = computed(() => props.photo?.stats ?? {});
const hasMetricStats = computed(() => {
    const s = stats.value;
    return s.impressions != null || s.clicks != null || s.ctr != null;
});

const isWinner = computed(() => !!props.photo?.is_winner);
const isCompleted = computed(() => props.experimentStatus === "completed");
const isRunning = computed(() => props.experimentStatus === "running");
const hasResultDelta = computed(
    () => isCompleted.value && stats.value?.result_delta_pct != null,
);

const showToolbar = computed(
    () => props.canDownload || props.editable || props.canDelete,
);

const resultClass = computed(() => {
    if (isWinner.value) {
        return "border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400";
    }
    return resultDeltaClass(stats.value?.result_delta_pct);
});

const resultLabel = computed(() => formatResultDelta(stats.value?.result_delta_pct));

const cardBorderClass = computed(() => {
    if (isWinner.value) {
        return "border-emerald-500/70 ring-2 ring-emerald-500/25 shadow-sm shadow-emerald-500/10";
    }
    if (props.isCurrent && isRunning.value) {
        return "border-primary/50 ring-2 ring-primary/15";
    }
    return "border-border/70";
});

function openReplace() {
    if (!props.editable || props.busy) {
        return;
    }
    replaceInput.value?.click();
}

function onReplaceChange(event) {
    const file = event.target?.files?.[0] ?? null;
    if (replaceInput.value) {
        replaceInput.value.value = "";
    }
    if (file) {
        emit("replace", file);
    }
}

function formatCtr(value) {
    if (value == null || value === "") {
        return "—";
    }
    const number = Number(value);
    if (Number.isNaN(number)) {
        return "—";
    }
    return `${number.toFixed(2)}%`;
}

function formatInt(value) {
    if (value == null || value === "") {
        return "—";
    }
    const number = Number(value);
    if (Number.isNaN(number)) {
        return "—";
    }
    return new Intl.NumberFormat("ru-RU").format(number);
}
</script>

<template>
    <Card
        class="flex flex-col overflow-hidden transition"
        :class="[cardBorderClass, busy ? 'opacity-70' : '']"
        :draggable="editable && draggable && !busy"
        @dragstart="editable && draggable && emit('drag-start', $event)"
        @dragover.prevent="editable && draggable && emit('drag-over', $event)"
        @drop.prevent="editable && draggable && emit('drop', $event)"
        @dragend="editable && draggable && emit('drag-end', $event)"
    >
        <div class="relative flex min-h-[240px] items-center justify-center bg-muted/40 sm:min-h-[280px]">
            <img
                v-if="photo.preview_url && !imgBroken"
                :src="photo.preview_url"
                :alt="photo.original_name || `Вариант ${index + 1}`"
                class="max-h-[280px] w-full object-contain p-2 sm:max-h-[320px]"
                loading="lazy"
                @error="imgBroken = true"
            />
            <div
                v-else
                class="flex flex-col items-center gap-2 text-muted-foreground"
            >
                <ImageOff class="h-8 w-8" />
                <span class="text-xs">Превью недоступно</span>
            </div>

            <div
                class="absolute left-2 top-2 flex items-center gap-1 rounded-md border border-border/70 bg-background/90 px-2 py-1 text-xs font-semibold shadow-sm backdrop-blur"
            >
                <GripVertical
                    v-if="editable && draggable"
                    class="h-3.5 w-3.5 text-muted-foreground"
                />
                #{{ index + 1 }}
            </div>

            <div
                v-if="isCurrent && isRunning"
                class="absolute bottom-2 left-2 rounded-md border border-primary/40 bg-primary/15 px-2 py-1 text-[11px] font-semibold text-primary shadow-sm backdrop-blur"
            >
                Сейчас на карточке
            </div>

            <div
                v-if="isWinner"
                class="absolute bottom-2 left-2 flex items-center gap-1 rounded-md border border-emerald-500/40 bg-emerald-500/15 px-2 py-1 text-[11px] font-semibold text-emerald-800 shadow-sm backdrop-blur dark:text-emerald-300"
                :class="isCurrent && isRunning ? 'left-auto right-2' : ''"
            >
                <Trophy class="h-3.5 w-3.5" />
                Победитель
            </div>

            <!--
                Панель действий: Download → Replace → Delete (слева направо:
                безопасное → правки → деструктивное). Всегда на одном месте.
            -->
            <div
                v-if="showToolbar"
                class="absolute right-2 top-2 flex items-center gap-0.5 rounded-lg border border-border/60 bg-background/95 p-0.5 shadow-md backdrop-blur supports-[backdrop-filter]:bg-background/80"
                role="toolbar"
                :aria-label="`Действия с вариантом ${index + 1}`"
            >
                <input
                    ref="replaceInput"
                    type="file"
                    class="hidden"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    @change="onReplaceChange"
                />
                <Button
                    v-if="canDownload"
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="h-8 w-8 p-0 text-muted-foreground hover:text-foreground"
                    title="Скачать фото"
                    aria-label="Скачать фото"
                    :disabled="busy || !photo.preview_url"
                    @click.stop="emit('download')"
                >
                    <Download class="h-3.5 w-3.5" />
                </Button>
                <Button
                    v-if="editable"
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="h-8 w-8 p-0 text-muted-foreground hover:text-foreground"
                    title="Заменить"
                    aria-label="Заменить фото"
                    :disabled="busy"
                    @click.stop="openReplace"
                >
                    <Replace class="h-3.5 w-3.5" />
                </Button>
                <Button
                    v-if="canDelete"
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="h-8 w-8 p-0 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                    title="Удалить вариант"
                    aria-label="Удалить вариант"
                    :disabled="busy"
                    @click.stop="emit('delete')"
                >
                    <Trash2 class="h-3.5 w-3.5" />
                </Button>
            </div>
        </div>

        <div class="min-h-[5.5rem] space-y-2 border-t border-border/60 p-3">
            <div class="flex items-start justify-between gap-2">
                <p class="truncate text-xs text-muted-foreground" :title="photo.original_name || ''">
                    {{ photo.original_name || `Вариант ${index + 1}` }}
                </p>

                <!-- Completed: lag % vs winner (winner = 0%) -->
                <span
                    v-if="hasResultDelta"
                    class="shrink-0 rounded-md border px-2 py-0.5 text-xs font-semibold tabular-nums"
                    :class="resultClass"
                    :title="isWinner ? 'Лучший CTR' : 'Отставание от победителя по CTR'"
                >
                    {{ resultLabel }}
                </span>
                <!-- Running: neutral collecting badge -->
                <span
                    v-else-if="isRunning && hasMetricStats"
                    class="shrink-0 rounded-md border border-border/60 bg-muted/40 px-2 py-0.5 text-[10px] font-medium text-muted-foreground"
                    title="Сравнение эффективности будет после завершения"
                >
                    Сбор статистики
                </span>
                <span
                    v-else-if="!hasMetricStats"
                    class="shrink-0 rounded-md border border-border/60 bg-muted/40 px-2 py-0.5 text-xs font-semibold tabular-nums text-muted-foreground"
                >
                    —
                </span>
            </div>
            <dl class="grid grid-cols-3 gap-2 text-center text-xs">
                <div class="rounded-md bg-muted/40 px-1.5 py-1.5">
                    <dt class="text-[10px] uppercase tracking-wide text-muted-foreground">Показы</dt>
                    <dd class="mt-0.5 font-medium tabular-nums">
                        {{ hasMetricStats ? formatInt(stats.impressions) : "—" }}
                    </dd>
                </div>
                <div class="rounded-md bg-muted/40 px-1.5 py-1.5">
                    <dt class="text-[10px] uppercase tracking-wide text-muted-foreground">Клики</dt>
                    <dd class="mt-0.5 font-medium tabular-nums">
                        {{ hasMetricStats ? formatInt(stats.clicks) : "—" }}
                    </dd>
                </div>
                <div class="rounded-md bg-muted/40 px-1.5 py-1.5">
                    <dt class="text-[10px] uppercase tracking-wide text-muted-foreground">CTR</dt>
                    <dd class="mt-0.5 font-medium tabular-nums">
                        {{ hasMetricStats ? formatCtr(stats.ctr) : "—" }}
                    </dd>
                </div>
            </dl>
        </div>
    </Card>
</template>
