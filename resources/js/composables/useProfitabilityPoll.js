import { usePage } from "@inertiajs/vue3";
import { onMounted, watch } from "vue";
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

    const poll = useToolPoll(5000, {
        requestOptions: {
            only: ["jobStatus", "report", "groups", "widget"],
            preserveState: true,
            preserveScroll: true,
        },
        isComplete: (props) => !isProcessing(props.jobStatus),
        onComplete: (props) => {
            if (props.jobStatus?.status === "failed") {
                onFailed?.(props.jobStatus.error || "Произошла ошибка при формировании отчёта. Попробуйте позже.");
            }
        },
    });

    function maybeStartPolling() {
        if (isProcessing(page.props.jobStatus)) {
            poll.start();
        }
    }

    onMounted(maybeStartPolling);

    watch(
        () => page.props.jobStatus?.status,
        (status, prev) => {
            if (status === "processing" && prev !== "processing") {
                poll.start();
            }
        },
    );

    return poll;
}