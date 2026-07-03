import { onBeforeUnmount, ref } from "vue";
import { aiFetch, extractAiMessage } from "@/composables/useAiGeneration";
import { toAiMediaUrl } from "@/composables/useAiMediaUrl";

const POLL_INTERVAL_MS = 4000;
const MAX_NETWORK_ERRORS = 5;

function normalizeVideoPayload(video) {
    if (!video || typeof video !== "object") {
        return null;
    }

    const sourceUrl = video.url || video.signed_url || video.url_preview || "";
    const normalizedUrl = toAiMediaUrl(sourceUrl);

    if (!normalizedUrl) {
        return null;
    }

    return {
        ...video,
        url: normalizedUrl,
        signed_url: normalizedUrl,
        url_preview: normalizedUrl,
    };
}

export function useAiVideoPoll({ onDone, onError, onLimitsUpdate, resolveTask } = {}) {
    const pollingTimer = ref(null);
    let networkErrors = 0;

    function stop() {
        if (pollingTimer.value) {
            clearTimeout(pollingTimer.value);
            pollingTimer.value = null;
        }
        networkErrors = 0;
    }

    async function checkStatus(requestId) {
        const taskInHistory = resolveTask?.(requestId) ?? null;

        try {
            const response = await aiFetch(`/panel/ai/video/status/${requestId}`);
            networkErrors = 0;

            const status = response?.data?.status;

            if (!response?.success) {
                stop();
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
                onError?.({ message: errorMsg, status, moderation: status === "filtered_by_moderation" });
                return;
            }

            if (!taskInHistory) {
                return;
            }

            if (status === "done") {
                const normalizedVideo = normalizeVideoPayload(response?.data?.video);

                if (!normalizedVideo) {
                    taskInHistory.status = "error";
                    taskInHistory.error = "Получен некорректный URL сгенерированного видео";
                    stop();
                    onError?.({ message: taskInHistory.error });
                    return;
                }

                taskInHistory.video = normalizedVideo;
                taskInHistory.status = status;
                onLimitsUpdate?.(response?.meta?.limits || response?.limits);
                stop();
                onDone?.(taskInHistory);
            } else if (status === "filtered_by_moderation") {
                taskInHistory.status = status;
                taskInHistory.error = extractAiMessage(
                    response,
                    "Видео не прошло модерацию. Измените запрос и попробуйте снова.",
                );
                stop();
                onError?.({ message: taskInHistory.error, status, moderation: true });
            } else if (status === "expired") {
                taskInHistory.status = status;
                taskInHistory.error = extractAiMessage(
                    response,
                    "Срок ожидания генерации видео истёк. Запустите генерацию повторно.",
                );
                stop();
                onError?.({ message: taskInHistory.error, status });
            } else if (status === "pending") {
                pollingTimer.value = setTimeout(() => checkStatus(requestId), POLL_INTERVAL_MS);
            }
        } catch (error) {
            networkErrors += 1;
            const task = resolveTask?.(requestId) ?? taskInHistory;

            if (networkErrors >= MAX_NETWORK_ERRORS) {
                stop();
                if (task) {
                    task.status = "error";
                    task.error = "Сбой соединения при проверке статуса видео";
                }
                onError?.({ message: "Сбой соединения при проверке статуса видео", network: true });
            } else {
                pollingTimer.value = setTimeout(() => checkStatus(requestId), POLL_INTERVAL_MS);
            }
        }
    }

    function start(requestId) {
        stop();
        pollingTimer.value = setTimeout(() => checkStatus(requestId), POLL_INTERVAL_MS);
    }

    onBeforeUnmount(stop);

    return {
        start,
        stop,
    };
}