import { router, usePage } from "@inertiajs/vue3";
import { ref, watch } from "vue";
import { useToolPoll } from "@/composables/useToolPoll";
import { useFlashToast } from "@/composables/useFlashToast";

const TERMINAL_STATUSES = ["stopped", "completed", "error"];

/**
 * Не затирать эксперимент на экране устаревшим ответом.
 * Старт и стоп приходят JSON-ом и обновляют локальную копию раньше, чем Inertia.
 *
 * @param {object|null|undefined} local
 * @param {object|null|undefined} incoming
 * @returns {object|null}
 */
export function acceptExperimentPayload(local, incoming) {
    if (!incoming) {
        return local?.id ? local : null;
    }
    if (!local || local.id !== incoming.id) {
        return incoming;
    }
    if (TERMINAL_STATUSES.includes(local.status) && incoming.status === "running") {
        return local;
    }
    if (
        local.status === "running" &&
        incoming.status !== "running" &&
        !TERMINAL_STATUSES.includes(incoming.status)
    ) {
        return local;
    }

    return incoming;
}

/**
 * Сумма кликов и показов в истории. null — поля истории в ответе нет.
 *
 * @param {object|null|undefined} experiment
 * @returns {number|null}
 */
export function actionHistoryScore(experiment) {
    if (!Array.isArray(experiment?.action_history)) {
        return null;
    }

    return experiment.action_history.reduce(
        (sum, row) => sum + (Number(row?.views) || 0) + (Number(row?.clicks) || 0),
        0,
    );
}

/**
 * Пока эксперимент идёт, таблица истории берётся из опроса страницы.
 * Локальная копия со старта и ответ списка фото cycles не содержат и до стопа оставляют таблицу пустой.
 *
 * @param {object|null|undefined} local
 * @param {object|null|undefined} polled
 * @returns {object|null}
 */
export function preferLiveExperiment(local, polled) {
    const picked = acceptExperimentPayload(local, polled);
    if (!local?.id || !polled?.id || local.id !== polled.id) {
        return picked;
    }
    if (local.status !== "running" || polled.status !== "running") {
        return picked;
    }

    const localScore = actionHistoryScore(local);
    const polledScore = actionHistoryScore(polled);
    const localAt = Date.parse(local.last_processed_at || "") || 0;
    const polledAt = Date.parse(polled.last_processed_at || "") || 0;

    if (polledScore == null) {
        if (localScore == null) {
            return polled;
        }

        return {
            ...polled,
            action_history: local.action_history,
            action_history_meta: local.action_history_meta ?? polled.action_history_meta,
        };
    }
    if (localScore == null || polledScore > localScore || polledAt >= localAt) {
        return polled;
    }

    return {
        ...polled,
        action_history: local.action_history,
        action_history_meta: local.action_history_meta ?? polled.action_history_meta,
    };
}

/**
 * Сумма показов, которую уже рисует экран.
 *
 * @param {object|null|undefined} experiment
 * @returns {number}
 */
export function experimentImpressions(experiment) {
    const fromProgress = Number(experiment?.impressions_progress?.total_views);
    if (Number.isFinite(fromProgress)) {
        return fromProgress;
    }
    const photos = Array.isArray(experiment?.photos) ? experiment.photos : [];

    return photos.reduce(
        (sum, photo) => sum + (Number(photo?.stats?.impressions ?? photo?.views) || 0),
        0,
    );
}

/**
 * Ответ кнопки не должен затирать более свежий опрос страницы.
 *
 * @param {object|null|undefined} local
 * @param {object|null|undefined} updated
 * @returns {object|null}
 */
export function mergeExperimentUpdate(local, updated) {
    if (!updated?.id) {
        return local ?? null;
    }
    if (!local || local.id !== updated.id) {
        return { ...(local ?? {}), ...updated };
    }

    const localAt = Date.parse(local.last_processed_at || "") || 0;
    const nextAt = Date.parse(updated.last_processed_at || "") || 0;
    if (local.status === "running" && nextAt && localAt && nextAt < localAt) {
        return local;
    }

    const localViews = experimentImpressions(local);
    const nextViews = experimentImpressions(updated);
    if (local.status === "running" && nextViews < localViews && nextAt <= localAt) {
        return {
            ...local,
            ...updated,
            photos: local.photos ?? updated.photos,
            impressions_progress: local.impressions_progress ?? updated.impressions_progress,
            action_history: local.action_history ?? updated.action_history,
            progress: local.progress,
            progress_mode: local.progress_mode,
            progress_label: local.progress_label,
        };
    }

    const nextHistory = Array.isArray(updated.action_history) ? updated.action_history : null;
    const localHistory = Array.isArray(local.action_history) ? local.action_history : null;
    let actionHistory = nextHistory ?? localHistory;
    if (
        local.status === "running" &&
        nextHistory &&
        localHistory &&
        actionHistoryScore(updated) < actionHistoryScore(local)
    ) {
        actionHistory = localHistory;
    }

    return {
        ...local,
        ...updated,
        action_history: actionHistory ?? updated.action_history,
        action_history_meta: actionHistory === localHistory
            ? (local.action_history_meta ?? updated.action_history_meta)
            : (updated.action_history_meta ?? local.action_history_meta),
    };
}

/**
 * Poll selectedExperiment while A/B experiment is running.
 * Первый запрос уходит сразу: интервал Inertia сам по себе ждёт 5 секунд.
 *
 * @param {object} [options]
 * @param {() => boolean} [options.shouldPoll] - extra gate (e.g. workspace view)
 * @param {() => object|null|undefined} [options.experiment] - эксперимент на экране, не только props страницы
 */
export function useAbExperimentPoll(options = {}) {
    const { shouldPoll = () => true, experiment = null } = options;
    const page = usePage();
    const { showError } = useFlashToast();
    const lastKnownStatus = ref(null);

    function shownExperiment() {
        const local = typeof experiment === "function" ? experiment() : null;
        const fromPage = page.props.selectedExperiment ?? null;
        if (local?.id) {
            return local;
        }

        return fromPage;
    }

    function pollRequestOptions() {
        const current = shownExperiment();
        const params = new URLSearchParams(window.location.search);
        if (current?.id) {
            params.set("experiment_id", String(current.id));
        }
        if (current?.ab_product_id) {
            params.set("product_id", String(current.ab_product_id));
        }
        const data = {};
        params.forEach((value, key) => {
            data[key] = value;
        });

        return {
            data,
            only: ["selectedExperiment"],
            preserveState: true,
            preserveScroll: true,
            headers: {
                "Cache-Control": "no-cache",
                Pragma: "no-cache",
            },
        };
    }

    const poll = useToolPoll(5000, {
        requestOptions: pollRequestOptions,
        isComplete: () => {
            if (!shouldPoll()) {
                return true;
            }

            return shownExperiment()?.status !== "running";
        },
    });

    function workspaceUrlHas(current) {
        const params = new URLSearchParams(window.location.search);

        return params.get("experiment_id") === String(current.id);
    }

    function refreshNow(current) {
        if (workspaceUrlHas(current)) {
            router.reload({
                only: ["selectedExperiment"],
                preserveState: true,
                preserveScroll: true,
                headers: {
                    "Cache-Control": "no-cache",
                    Pragma: "no-cache",
                },
            });
            return;
        }

        const params = new URLSearchParams(window.location.search);
        const productId = current.ab_product_id || params.get("product_id");
        if (!productId) {
            return;
        }
        params.set("product_id", String(productId));
        params.set("experiment_id", String(current.id));
        router.get(
            `${window.location.pathname}?${params.toString()}`,
            {},
            {
                only: ["selectedProduct", "selectedExperiment", "experiments", "filters"],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    function syncFromProps() {
        if (!shouldPoll()) {
            poll.stop();
            return;
        }

        const current = shownExperiment();
        const status = current?.status ?? null;
        if (lastKnownStatus.value === "running" && status === "error") {
            showError(
                current?.error_message ||
                    current?.last_api_error ||
                    "Эксперимент остановлен из‑за ошибки API",
            );
        }
        lastKnownStatus.value = status;

        if (status === "running" && current?.id) {
            if (!poll.isPolling.value) {
                poll.start();
                refreshNow(current);
            }
            return;
        }

        poll.stop();
    }

    watch(
        () => [
            shownExperiment()?.id,
            shownExperiment()?.status,
            page.props.selectedExperiment?.id,
            page.props.selectedExperiment?.status,
            page.props.selectedExperiment?.error_message,
            shouldPoll(),
        ],
        () => syncFromProps(),
        { immediate: true },
    );

    return {
        ...poll,
        syncFromProps,
    };
}
