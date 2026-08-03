import { usePage } from "@inertiajs/vue3";
import { onMounted, ref, watch } from "vue";
import { useToolPoll } from "@/composables/useToolPoll";

function isProcessing(jobStatus = {}) {
    return jobStatus?.status === "processing";
}

/**
 * @param {object} options
 * @param {(message: string) => void} [options.onFailed]
 * @param {(message: string, meta?: { toast?: boolean }) => void} [options.onSuccess]
 */
export function useProfitabilityPoll(options = {}) {
    const { onFailed, onSuccess } = options;
    const page = usePage();
    const hasTrackedActiveJob = ref(false);
    const lastNotifiedKey = ref(null);

    function notifySettled(props) {
        const status = props.jobStatus?.status;
        if (status !== "done" && status !== "failed") {
            return;
        }

        const key = [
            status,
            props.jobStatus?.started_at ?? "",
            props.jobStatus?.status_label ?? "",
            props.jobStatus?.error ?? "",
        ].join("|");

        if (lastNotifiedKey.value === key) {
            return;
        }
        lastNotifiedKey.value = key;

        if (status === "failed") {
            onFailed?.(
                props.jobStatus.error ||
                    "Не удалось сформировать отчёт. Попробуйте позже.",
            );
            return;
        }

        const message =
            props.jobStatus.success_message ||
            props.jobStatus.status_label ||
            "Расчёт завершён. Отчёт готов.";

        const flashSuccess = page.props.flash?.success;
        if (flashSuccess && flashSuccess === message) {
            onSuccess?.(message, { toast: false });
            return;
        }

        onSuccess?.(message, { toast: true });
    }

    const poll = useToolPoll(2500, {
        requestOptions: {
            only: ["jobStatus", "report", "widget", "groupMeta"],
            preserveState: true,
            preserveScroll: true,
        },
        isComplete: (props) => !isProcessing(props.jobStatus),
        onComplete: (props) => {
            if (!hasTrackedActiveJob.value) {
                return;
            }

            hasTrackedActiveJob.value = false;
            notifySettled(props);
        },
    });

    function startPolling() {
        hasTrackedActiveJob.value = true;

        // Job may already be finished on the same response (fast / sync queue).
        if (!isProcessing(page.props.jobStatus)) {
            hasTrackedActiveJob.value = false;
            notifySettled(page.props);
            return;
        }

        poll.start();
    }

    function maybeStartPolling() {
        if (isProcessing(page.props.jobStatus)) {
            startPolling();
        }
    }

    onMounted(maybeStartPolling);

    watch(
        () => page.props.jobStatus?.status,
        (status, prev) => {
            if (status === "processing" && prev !== "processing") {
                startPolling();
            }
        },
    );

    return {
        ...poll,
        start: startPolling,
    };
}
