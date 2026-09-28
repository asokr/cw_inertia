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

    const poll = useToolPoll(5000, {
        requestOptions: {
            only: ["selectedExperiment"],
            preserveState: true,
            preserveScroll: true,
        },
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
