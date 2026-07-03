import { usePage } from "@inertiajs/vue3";
import { onMounted, onUnmounted, ref, watch } from "vue";
import { useToolPoll } from "@/composables/useToolPoll";

const POLLING_TIMEOUT_MS = 15 * 60 * 1000;

function isProcessing(report = null) {
    return report?.status === "processing";
}

/**
 * @param {object} options
 * @param {(message: string) => void} [options.onFailed]
 * @param {(message: string) => void} [options.onTimedOut]
 */
export function useAiCabinetReportPoll(options = {}) {
    const { onFailed, onTimedOut } = options;
    const page = usePage();
    const startedAt = ref(null);
    const timedOut = ref(false);
    const timeoutTimer = ref(null);

    const poll = useToolPoll(5000, {
        requestOptions: () => ({
            only: ["report", "meta", "nomenclatures", "nomenclaturesMeta", "analyses", "analysesMeta"],
            preserveState: true,
            preserveScroll: true,
            data: {
                report_id: page.props.report?.id,
                page: page.props.nomenclatureFilters?.page,
                per_page: page.props.nomenclatureFilters?.per_page,
                nmid: page.props.nomenclatureFilters?.nmid,
                advert_id: page.props.nomenclatureFilters?.advert_id,
            },
        }),
        isComplete: (props) => !isProcessing(props.report),
        onComplete: (props) => {
            clearTimeoutWatcher();
            if (props.report?.status === "failed") {
                onFailed?.(props.report.error || "Ошибка обработки отчёта");
            }
        },
    });

    function clearTimeoutWatcher() {
        if (timeoutTimer.value) {
            clearTimeout(timeoutTimer.value);
            timeoutTimer.value = null;
        }
    }

    function armTimeout() {
        clearTimeoutWatcher();
        startedAt.value = Date.now();
        timedOut.value = false;
        timeoutTimer.value = setTimeout(() => {
            if (isProcessing(page.props.report)) {
                timedOut.value = true;
                poll.stop();
                onTimedOut?.("Время ожидания истекло. Обновите статус вручную или перезапустите сбор данных.");
            }
        }, POLLING_TIMEOUT_MS);
    }

    function maybeStartPolling() {
        if (isProcessing(page.props.report)) {
            armTimeout();
            poll.start();
        }
    }

    onMounted(maybeStartPolling);

    watch(
        () => page.props.report?.status,
        (status, prev) => {
            if (status === "processing" && prev !== "processing") {
                armTimeout();
                poll.start();
            }
            if (status !== "processing") {
                clearTimeoutWatcher();
            }
        },
    );

    onUnmounted(clearTimeoutWatcher);

    return { ...poll, timedOut };
}