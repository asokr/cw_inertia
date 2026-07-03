import { usePage, usePoll } from "@inertiajs/vue3";
import { onUnmounted, ref, watch } from "vue";

/**
 * Polling helper for long-running tool jobs (sync, calculate, report status, etc.).
 *
 * @param {number} intervalMs
 * @param {object} options
 * @param {import('@inertiajs/core').ReloadOptions|(() => import('@inertiajs/core').ReloadOptions)} [options.requestOptions]
 * @param {import('@inertiajs/core').PollOptions} [options.pollOptions]
 * @param {(props: object) => boolean} [options.isComplete] - stop polling when returns true
 * @param {(props: object) => void} [options.onComplete]
 */
export function useToolPoll(intervalMs = 3000, options = {}) {
    const {
        requestOptions = {},
        pollOptions = {},
        isComplete = null,
        onComplete = null,
    } = options;

    const page = usePage();
    const isPolling = ref(false);

    const resolveRequestOptions = typeof requestOptions === "function"
        ? requestOptions
        : () => requestOptions;

    const { start, stop } = usePoll(intervalMs, resolveRequestOptions, {
        autoStart: false,
        keepAlive: false,
        ...pollOptions,
    });

    function evaluateCompletion() {
        if (!isPolling.value || typeof isComplete !== "function") {
            return;
        }

        if (isComplete(page.props)) {
            stopPolling();
            onComplete?.(page.props);
        }
    }

    watch(() => page.props, evaluateCompletion, { deep: true });

    function startPolling() {
        isPolling.value = true;
        start();
        evaluateCompletion();
    }

    function stopPolling() {
        isPolling.value = false;
        stop();
    }

    onUnmounted(stopPolling);

    return {
        start: startPolling,
        stop: stopPolling,
        isPolling,
    };
}