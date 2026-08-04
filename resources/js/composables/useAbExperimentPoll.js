import { usePage } from "@inertiajs/vue3";
import { ref, watch } from "vue";
import { useToolPoll } from "@/composables/useToolPoll";
import { useFlashToast } from "@/composables/useFlashToast";

/**
 * Poll selectedExperiment while A/B experiment is running.
 * Relies on job ticks for mid-flight stats; does not call WB fullstats on GET.
 *
 * @param {object} [options]
 * @param {() => boolean} [options.shouldPoll] - extra gate (e.g. workspace view)
 */
export function useAbExperimentPoll(options = {}) {
    const { shouldPoll = () => true } = options;
    const page = usePage();
    const { showError } = useFlashToast();
    const lastKnownStatus = ref(page.props.selectedExperiment?.status ?? null);

    const poll = useToolPoll(5000, {
        requestOptions: {
            only: ["selectedExperiment"],
            preserveState: true,
            preserveScroll: true,
        },
        isComplete: (props) => {
            if (!shouldPoll()) {
                return true;
            }
            const status = props.selectedExperiment?.status;
            return status !== "running";
        },
    });

    function isRunningStatus(status) {
        return status === "running";
    }

    function syncFromProps() {
        if (!shouldPoll()) {
            poll.stop();
            return;
        }

        const experiment = page.props.selectedExperiment;
        const status = experiment?.status;
        if (
            lastKnownStatus.value === "running" &&
            status === "error"
        ) {
            showError(
                experiment?.error_message ||
                    experiment?.last_api_error ||
                    "Эксперимент остановлен из‑за ошибки API",
            );
        }
        lastKnownStatus.value = status ?? null;

        if (isRunningStatus(status)) {
            if (!poll.isPolling.value) {
                poll.start();
            }
            return;
        }

        poll.stop();
    }

    watch(
        () => [
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
