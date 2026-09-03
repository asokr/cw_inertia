<script setup>
import { computed, ref } from "vue";
import axios from "axios";
import {
    AlertTriangle,
    CheckCircle2,
    Circle,
    Loader2,
    Play,
    Square,
} from "lucide-vue-next";
import SelectedProductCard from "./SelectedProductCard.vue";
import ExperimentActionHistory from "./ExperimentActionHistory.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    product: {
        type: Object,
        default: null,
    },
    experiment: {
        type: Object,
        default: null,
    },
    baseUrl: {
        type: String,
        required: true,
    },
    /** Hide step chrome when embedded in workspace. */
    embedded: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["experiment-updated"]);

const { showError, showSuccess } = useFlashToast();

const busy = ref(false);

const status = computed(() => props.experiment?.status ?? "draft");
const isRunning = computed(() => status.value === "running");
/** draft / stopped / error — can show start checklist and (re)start */
const canShowStart = computed(
    () =>
        status.value === "draft" ||
        status.value === "stopped" ||
        status.value === "error",
);
/** completed only — no restart from completed */
const isReadonlyTerminal = computed(() => status.value === "completed");
const isError = computed(() => status.value === "error");
const isRestartable = computed(
    () => status.value === "stopped" || status.value === "error",
);
const consecutiveFailures = computed(
    () => Number(props.experiment?.consecutive_failures) || 0,
);
const maxConsecutiveFailures = computed(
    () => Number(props.experiment?.max_consecutive_failures) || 5,
);
const lastApiError = computed(
    () => props.experiment?.last_api_error || props.experiment?.error_message || null,
);
const actionHistoryMeta = computed(
    () => props.experiment?.action_history_meta ?? null,
);
const events = computed(() =>
    Array.isArray(props.experiment?.events) ? props.experiment.events : [],
);

const checks = computed(() =>
    Array.isArray(props.experiment?.start_checks) ? props.experiment.start_checks : [],
);
const allChecksOk = computed(() => checks.value.every((c) => c.ok));
const canStart = computed(
    () => !!props.experiment?.can_start && allChecksOk.value && !busy.value,
);
const canStop = computed(() => !!props.experiment?.can_stop && !busy.value);

const actionHistory = computed(() => props.experiment?.action_history ?? []);

const progressMode = computed(() => props.experiment?.progress_mode ?? "setup");
const progressPercent = computed(() =>
    Math.max(0, Math.min(100, Math.round(Number(props.experiment?.progress) || 0))),
);
const progressLabel = computed(
    () => props.experiment?.progress_label || `${progressPercent.value}%`,
);
const impressionsProgress = computed(() => props.experiment?.impressions_progress ?? null);
const isProgressPending = computed(() => progressMode.value === "pending");

function formatInt(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return "—";
    }
    return Math.round(n).toLocaleString("ru-RU");
}

const startUrl = computed(() => {
    if (!props.experiment?.id) {
        return null;
    }
    return `${props.baseUrl}/experiments/${props.experiment.id}/start`;
});

const stopUrl = computed(() => {
    if (!props.experiment?.id) {
        return null;
    }
    return `${props.baseUrl}/experiments/${props.experiment.id}/stop`;
});

async function start() {
    if (!startUrl.value || !canStart.value) {
        return;
    }

    busy.value = true;
    try {
        const { data } = await axios.post(startUrl.value);
        if (!data?.success) {
            showError(
                (data?.messages && data.messages[0]) ||
                    "Не удалось запустить эксперимент",
            );
            return;
        }
        if (data.experiment) {
            emit("experiment-updated", data.experiment);
        }
        showSuccess((data.messages && data.messages[0]) || "Эксперимент запущен");
    } catch (error) {
        const messages = error?.response?.data?.messages;
        showError(
            messages?.[0] ||
                error?.response?.data?.message ||
                "Не удалось запустить эксперимент",
        );
    } finally {
        busy.value = false;
    }
}

async function stop() {
    if (!stopUrl.value || !canStop.value) {
        return;
    }

    if (!window.confirm("Остановить эксперимент? Кампания будет приостановлена, статистика сохранится.")) {
        return;
    }

    busy.value = true;
    try {
        const { data } = await axios.post(stopUrl.value);
        if (!data?.success) {
            showError(
                (data?.messages && data.messages[0]) ||
                    "Не удалось остановить эксперимент",
            );
            return;
        }
        if (data.experiment) {
            emit("experiment-updated", data.experiment);
        }
        showSuccess((data.messages && data.messages[0]) || "Эксперимент остановлен");
    } catch (error) {
        const messages = error?.response?.data?.messages;
        showError(
            messages?.[0] ||
                error?.response?.data?.message ||
                "Не удалось остановить эксперимент",
        );
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="space-y-4">
        <div v-if="!embedded" class="space-y-1">
            <h3 class="text-lg font-semibold">Запуск эксперимента</h3>
            <p class="text-sm text-muted-foreground">
                После запуска смена фотографий, сбор статистики и завершение
                выполняются автоматически в фоне — браузер можно закрыть.
            </p>
        </div>
        <div v-else class="space-y-1">
            <h3 class="text-base font-semibold">Управление и статистика</h3>
            <p class="text-sm text-muted-foreground">
                Запуск и остановка, прогресс по показам, журнал событий.
            </p>
        </div>

        <SelectedProductCard v-if="product" :product="product" />

        <Card v-if="canShowStart" class="space-y-4 p-4 sm:p-5">
            <div
                v-if="isError"
                class="flex gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2.5 text-sm text-destructive"
            >
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                <div class="min-w-0 space-y-1">
                    <p class="font-semibold">Эксперимент остановлен из‑за ошибки</p>
                    <p class="text-xs leading-relaxed text-destructive/90">
                        {{
                            experiment.error_message ||
                                lastApiError ||
                                "Неизвестная ошибка. Можно исправить настройки и запустить снова."
                        }}
                    </p>
                    <p
                        v-if="experiment.finished_at"
                        class="text-[11px] text-muted-foreground"
                    >
                        Время:
                        {{ new Date(experiment.finished_at).toLocaleString("ru-RU") }}
                    </p>
                </div>
            </div>

            <div>
                <p class="text-sm font-semibold text-foreground">
                    {{ isRestartable ? "Повторный запуск" : "Проверка готовности" }}
                </p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Все пункты должны быть выполнены перед запуском.
                </p>
            </div>
            <ul class="space-y-2">
                <li
                    v-for="check in checks"
                    :key="check.key"
                    class="flex items-center gap-2 text-sm"
                >
                    <CheckCircle2
                        v-if="check.ok"
                        class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                    />
                    <Circle
                        v-else
                        class="h-4 w-4 shrink-0 text-muted-foreground"
                    />
                    <span :class="check.ok ? 'text-foreground' : 'text-muted-foreground'">
                        {{ check.label }}
                    </span>
                </li>
            </ul>

            <div
                v-if="!allChecksOk"
                class="flex gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-200"
            >
                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>
                    Сохраните настройки, загрузите минимум 2 фото и привяжите рекламную кампанию.
                </span>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border/50 pt-3">
                <p class="text-xs text-muted-foreground">
                    {{
                        isRestartable
                            ? "Перезапуск: история циклов сохранится, счётчик ошибок сбросится, откроется новый цикл."
                            : "Запуск: кампания Ozon → первая фотография → цикл → «В процессе»."
                    }}
                </p>
                <Button :disabled="!canStart" @click="start">
                    <Loader2 v-if="busy" class="mr-1.5 h-4 w-4 animate-spin" />
                    <Play v-else class="mr-1.5 h-4 w-4" />
                    {{ isRestartable ? "Запустить снова" : "Запустить эксперимент" }}
                </Button>
            </div>
        </Card>

        <Card v-else-if="isRunning" class="space-y-4 p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-foreground">Эксперимент выполняется</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Смена фото — что наступит раньше: лимит «показов за круг» или
                        «длительность круга». Завершение — когда каждое фото наберёт
                        «показов на одно фото».
                    </p>
                    <p
                        v-if="experiment.last_processed_at"
                        class="mt-1 text-[11px] text-muted-foreground"
                    >
                        Последняя обработка:
                        {{ new Date(experiment.last_processed_at).toLocaleString("ru-RU") }}
                    </p>
                </div>
                <Button variant="destructive" size="sm" :disabled="!canStop" @click="stop">
                    <Loader2 v-if="busy" class="mr-1.5 h-4 w-4 animate-spin" />
                    <Square v-else class="mr-1.5 h-3.5 w-3.5" />
                    Остановить
                </Button>
            </div>

            <div
                v-if="consecutiveFailures > 0 || lastApiError"
                class="flex gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-900 dark:text-amber-100"
            >
                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <div class="min-w-0 space-y-0.5">
                    <p class="font-medium">
                        Проблемы API
                        <span v-if="consecutiveFailures > 0" class="tabular-nums">
                            ({{ consecutiveFailures }}/{{ maxConsecutiveFailures }})
                        </span>
                    </p>
                    <p v-if="lastApiError" class="text-amber-800/90 dark:text-amber-200/90">
                        {{ lastApiError }}
                    </p>
                    <p class="text-[11px] text-muted-foreground">
                        Если Ozon временно не принимает запросы, эксперимент не останавливается —
                        повтор будет позже. После {{ maxConsecutiveFailures }} прочих
                        сбоев подряд статус станет «Ошибка».
                    </p>
                </div>
            </div>

            <div class="space-y-2">
                <div class="h-2 overflow-hidden rounded-full bg-muted">
                    <div
                        v-if="isProgressPending"
                        class="h-full w-1/5 animate-pulse rounded-full bg-primary/50"
                    />
                    <div
                        v-else
                        class="h-full rounded-full bg-primary transition-all"
                        :style="{ width: `${progressPercent}%` }"
                    />
                </div>
                <p class="text-xs text-muted-foreground">
                    <template v-if="isProgressPending">
                        <span class="font-medium text-foreground">Прогресс:</span>
                        ожидаем первые показы из статистики Ozon…
                    </template>
                    <template v-else>
                        <span class="font-medium text-foreground">Прогресс:</span>
                        <span class="tabular-nums"> {{ progressLabel }}</span>
                    </template>
                </p>
                <ul
                    v-if="impressionsProgress?.photos?.length && !isProgressPending"
                    class="space-y-0.5 text-[11px] tabular-nums text-muted-foreground"
                >
                    <li
                        v-for="(row, idx) in impressionsProgress.photos"
                        :key="row.id"
                    >
                        Фото {{ idx + 1 }}:
                        {{ formatInt(row.views) }}
                        /
                        {{ formatInt(impressionsProgress.target_per_photo) }}
                        показов
                        ({{ Math.round((row.ratio || 0) * 100) }}%)
                    </li>
                </ul>
            </div>
        </Card>

        <Card v-else-if="isReadonlyTerminal" class="space-y-3 p-4 sm:p-5">
            <p class="text-sm font-semibold text-foreground">
                {{ experiment.status_label }}
            </p>
            <p class="text-xs text-muted-foreground">
                Эксперимент завершён и доступен только для просмотра.
                Для нового теста создайте новый эксперимент.
            </p>
            <p
                v-if="experiment.winner_photo_id"
                class="text-xs text-muted-foreground"
            >
                Победитель по CTR установлен главным фото в карточке Ozon
                (фото #{{ experiment.winner_photo_id }}).
            </p>
            <p
                v-if="experiment.error_message"
                class="text-xs text-amber-800 dark:text-amber-200"
            >
                {{ experiment.error_message }}
            </p>
        </Card>

        <Card
            v-if="events.length && (isRunning || isError || isReadonlyTerminal || status === 'stopped')"
            class="space-y-2 p-4 sm:p-5"
        >
            <p class="text-sm font-semibold text-foreground">Журнал событий</p>
            <ul class="max-h-48 space-y-1.5 overflow-auto text-xs">
                <li
                    v-for="ev in events"
                    :key="ev.id"
                    class="border-b border-border/40 pb-1.5 last:border-0"
                >
                    <p class="text-[11px] text-muted-foreground tabular-nums">
                        {{
                            ev.created_at
                                ? new Date(ev.created_at).toLocaleString("ru-RU")
                                : "—"
                        }}
                        <span class="ml-1 opacity-70">{{ ev.type }}</span>
                    </p>
                    <p
                        class="text-foreground"
                        :class="{
                            'text-destructive':
                                String(ev.type || '').includes('error') ||
                                String(ev.type || '').includes('rate_limited'),
                        }"
                    >
                        {{ ev.message }}
                    </p>
                </li>
            </ul>
        </Card>

        <ExperimentActionHistory
            v-if="
                actionHistory.length ||
                isRunning ||
                isReadonlyTerminal ||
                status === 'stopped' ||
                isError
            "
            :rows="actionHistory"
            :meta="actionHistoryMeta"
        />
    </div>
</template>
