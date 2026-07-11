import { onBeforeUnmount, ref } from "vue";
import { aiFetch, extractAiMessage } from "@/composables/useAiGeneration";
import { normalizeVideoItem } from "@/composables/useAiMediaUrl";

const POLL_INTERVAL_MS = 4000;
const MAX_NETWORK_ERRORS = 5;

function normalizeVideoPayload(video) {
    return normalizeVideoItem(video);
}

export function useAiVideoPoll({ onDone, onError, onLimitsUpdate, resolveTask } = {}) {
    const pollingTimers = ref(new Map());
    const networkErrors = ref(new Map());

    function stop(requestId = null) {
        if (requestId) {
            const timer = pollingTimers.value.get(requestId);
            if (timer) {
                clearTimeout(timer);
                pollingTimers.value.delete(requestId);
            }
            networkErrors.value.delete(requestId);
            return;
        }

        for (const timer of pollingTimers.value.values()) {
            clearTimeout(timer);
        }
        pollingTimers.value.clear();
        networkErrors.value.clear();
    }

    async function checkStatus(requestId) {
        const taskInHistory = resolveTask?.(requestId) ?? null;

        try {
            const response = await aiFetch(`/panel/ai/video/status/${requestId}`);
            networkErrors.value.set(requestId, 0);

            const status = response?.data?.status;

            if (!response?.success) {
                stop(requestId);
                const errorMsg = extractAiMessage(response, "Ошибка при генерации видео");
                if (taskInHistory) {
                    if (status === "expired") {
                        taskInHistory.status = "expired";
                    } else if (status === "filtered_by_moderation") {
                        taskInHistory.status = "filtered_by_moderation";
                    } else {
                        taskInHistory.status = "error";
                    }
                    taskInHistory.error = errorMsg;
                }
                onError?.({ message: errorMsg, status, moderation: status === "filtered_by_moderation", requestId });
                return;
            }

            if (!taskInHistory) {
                stop(requestId);
                return;
            }

            if (status === "done") {
                const wasPending = taskInHistory.status === "pending";
                const normalizedVideo = normalizeVideoPayload(response?.data?.video);

                if (!normalizedVideo) {
                    taskInHistory.status = "error";
                    taskInHistory.error = "Получен некорректный URL сгенерированного видео";
                    stop(requestId);
                    onError?.({ message: taskInHistory.error, requestId });
                    return;
                }

                taskInHistory.video = normalizedVideo;
                taskInHistory.status = status;
                onLimitsUpdate?.(response?.meta?.limits || response?.limits);
                stop(requestId);

                if (wasPending) {
                    onDone?.(taskInHistory);
                }
            } else if (status === "filtered_by_moderation") {
                taskInHistory.status = status;
                taskInHistory.error = extractAiMessage(
                    response,
                    "Видео не прошло модерацию. Измените запрос и попробуйте снова.",
                );
                stop(requestId);
                onError?.({ message: taskInHistory.error, status, moderation: true, requestId });
            } else if (status === "expired") {
                taskInHistory.status = status;
                taskInHistory.error = extractAiMessage(
                    response,
                    "Срок ожидания генерации видео истёк. Запустите генерацию повторно.",
                );
                stop(requestId);
                onError?.({ message: taskInHistory.error, status, requestId });
            } else if (status === "pending") {
                const timer = setTimeout(() => checkStatus(requestId), POLL_INTERVAL_MS);
                pollingTimers.value.set(requestId, timer);
            } else {
                stop(requestId);
            }
        } catch (error) {
            const currentErrors = (networkErrors.value.get(requestId) ?? 0) + 1;
            networkErrors.value.set(requestId, currentErrors);
            const task = resolveTask?.(requestId) ?? taskInHistory;

            if (currentErrors >= MAX_NETWORK_ERRORS) {
                stop(requestId);
                if (task) {
                    task.status = "error";
                    task.error = "Сбой соединения при проверке статуса видео";
                }
                onError?.({ message: "Сбой соединения при проверке статуса видео", network: true, requestId });
            } else {
                const timer = setTimeout(() => checkStatus(requestId), POLL_INTERVAL_MS);
                pollingTimers.value.set(requestId, timer);
            }
        }
    }

    function start(requestId) {
        if (!requestId) {
            return;
        }

        stop(requestId);
        const timer = setTimeout(() => checkStatus(requestId), POLL_INTERVAL_MS);
        pollingTimers.value.set(requestId, timer);
    }

    function resumePending(tasks = []) {
        if (!Array.isArray(tasks)) {
            return;
        }

        tasks
            .filter((task) => task?.status === "pending" && task?.request_id)
            .forEach((task) => start(task.request_id));
    }

    onBeforeUnmount(() => stop());

    return {
        start,
        stop,
        resumePending,
    };
}