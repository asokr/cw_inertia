import { usePage } from "@inertiajs/vue3";
import { onMounted, ref, watch } from "vue";
import { useToolPoll } from "@/composables/useToolPoll";

function isProcessing(jobStatus = {}) {
    return jobStatus.status === "processing";
}

/**
 * @param {object} options
 * @param {(message: string) => void} [options.onFailed]
 */
export function useProfitabilityPoll(options = {}) {
    const { onFailed } = options;
    const page = usePage();
    const hasTrackedActiveJob = ref(false);

    const poll = useToolPoll(5000, {
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

            if (props.jobStatus?.status === "failed") {
                onFailed?.(props.jobStatus.error || "Произошла ошибка при формировании отчёта. Попробуйте позже.");
            }
        },
    });

    function startPolling() {
        hasTrackedActiveJob.value = true;
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