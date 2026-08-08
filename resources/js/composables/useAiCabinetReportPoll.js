import { usePage } from "@inertiajs/vue3";
import { onMounted, onUnmounted, ref, watch } from "vue";
import { useToolPoll } from "@/composables/useToolPoll";

/**
 * Долгий сбор (воронка WB 1 req/min) — не прерываем polling клиентским «timeout».
 * Подсказку «долго идёт» показываем мягко, без ошибки и без остановки опроса.
 */
const LONG_RUNNING_HINT_MS = 30 * 60 * 1000;

export const LONG_RUNNING_MESSAGE =
    "Сбор данных ещё идёт из‑за ограничений Wildberries и может занять продолжительное время. Страницу можно закрыть — сбор завершится сам; статус обновится при следующем открытии или вручную.";

function isProcessing(report = null) {
    return report?.status === "processing";
}

/**
 * @param {object} options
 * @param {(message: string) => void} [options.onFailed]
 * @param {(message: string) => void} [options.onLongRunning]
 */
export function useAiCabinetReportPoll(options = {}) {
    const { onFailed, onLongRunning } = options;
    const page = usePage();
    const longRunning = ref(false);
    const hintTimer = ref(null);

    const poll = useToolPoll(5000, {
        requestOptions: () => ({
            only: [
                "report",
                "meta",
                "nomenclatures",
                "nomenclaturesMeta",
                "products",
                "productsMeta",
                "analyses",
                "analysesMeta",
            ],
            preserveState: true,
            preserveScroll: true,
            data: {
                report_id: page.props.report?.id,
                page: page.props.nomenclatureFilters?.page ?? page.props.productFilters?.page,
                per_page: page.props.nomenclatureFilters?.per_page ?? page.props.productFilters?.per_page,
                nmid: page.props.nomenclatureFilters?.nmid,
                advert_id: page.props.nomenclatureFilters?.advert_id,
                product_id: page.props.productFilters?.product_id,
                offer_id: page.props.productFilters?.offer_id,
                q: page.props.productFilters?.q,
            },
        }),
        isComplete: (props) => !isProcessing(props.report),
        onComplete: (props) => {
            clearHintTimer();
            longRunning.value = false;
            if (props.report?.status === "failed") {
                onFailed?.(props.report.error || "Ошибка обработки отчёта");
            }
        },
    });

    function clearHintTimer() {
        if (hintTimer.value) {
            clearTimeout(hintTimer.value);
            hintTimer.value = null;
        }
    }

    function armLongRunningHint() {
        clearHintTimer();
        longRunning.value = false;
        hintTimer.value = setTimeout(() => {
            if (isProcessing(page.props.report)) {
                longRunning.value = true;
                onLongRunning?.(LONG_RUNNING_MESSAGE);
            }
        }, LONG_RUNNING_HINT_MS);
    }

    function start() {
        if (!isProcessing(page.props.report)) {
            longRunning.value = false;
            clearHintTimer();
            return;
        }
        armLongRunningHint();
        poll.start();
    }

    function stop() {
        clearHintTimer();
        poll.stop();
    }

    onMounted(() => {
        if (isProcessing(page.props.report)) {
            start();
        }
    });

    watch(
        () => page.props.report?.status,
        (status, prev) => {
            if (status === "processing" && prev !== "processing") {
                start();
            }
            if (status !== "processing") {
                clearHintTimer();
                longRunning.value = false;
                poll.stop();
            }
        },
    );

    onUnmounted(() => {
        clearHintTimer();
        poll.stop();
    });

    return {
        start,
        stop,
        isPolling: poll.isPolling,
        /** @deprecated use longRunning — left for compatibility with timedOut prop */
        timedOut: longRunning,
        longRunning,
    };
}
