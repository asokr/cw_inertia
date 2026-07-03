import { computed, ref } from "vue";
import { aiFetch, extractAiMessage } from "@/composables/useAiGeneration";
import { mapAiImageItem, toAiMediaUrl } from "@/composables/useAiMediaUrl";
import { useAiVideoPoll } from "@/composables/useAiVideoPoll";

const IMAGE_TASK_TYPES = new Set(["generate_image", "edit_image"]);

function stripMarkdown(text) {
    if (typeof text !== "string") {
        return text;
    }

    let res = text;
    res = res.replace(/\*\*(.*?)\*\*/g, "$1");
    res = res.replace(/^#+\s+/gm, "");
    res = res.replace(/^\s*\*\s+/gm, "- ");
    res = res.replace(/\*(.*?)\*/g, "$1");

    return res.trim();
}

function normalizeHistoryImage(value) {
    if (!value || typeof value !== "string") {
        return null;
    }

    if (value.startsWith("data:image/")) {
        return value;
    }

    return toAiMediaUrl(value, { allowDataUrl: true }) || null;
}

function normalizeHistoryImages(images) {
    if (!Array.isArray(images)) {
        return [];
    }

    return images.map((item) => normalizeHistoryImage(item)).filter(Boolean);
}

function buildVideoStartPayload(payload) {
    const body = {
        task_type: payload.task_type,
        prompt: String(payload.prompt || "").trim(),
        duration: Number(payload.duration || 5),
        resolution: payload.resolution || "480p",
    };

    if (payload.task_type === "generate_video" && payload.aspect_ratio) {
        body.aspect_ratio = payload.aspect_ratio;
    }

    if (payload.task_type === "generate_video_from_image" && payload.image) {
        body.image = payload.image;
    }

    return body;
}

function buildSceneVideoPayload(payload) {
    return {
        prompt: String(payload.prompt || "").trim(),
        duration: Number(payload.duration || 5),
        resolution: payload.resolution || "480p",
        images: Array.isArray(payload.images) ? payload.images : [],
    };
}

export function useMarketplaceAi(initialLimits = {}, { onVideoError, onVideoDone } = {}) {
    const loading = ref(false);
    const limitsLoading = ref(false);

    const textLimit = ref(initialLimits.text ?? 0);
    const imageLimit = ref(initialLimits.image ?? 0);
    const videoLimit = ref(initialLimits.video ?? 0);

    const textResult = ref("");
    const richDescriptionResult = ref("");
    const imageResults = ref([]);
    const videoHistory = ref([]);

    const hasTextLimit = computed(() => Number(textLimit.value ?? 0) > 0);
    const hasImageLimit = computed(() => Number(imageLimit.value ?? 0) > 0);
    const hasVideoLimit = computed(() => Number(videoLimit.value ?? 0) > 0);

    function applyLimits(limits) {
        if (!limits || typeof limits !== "object") {
            return;
        }

        if (limits.AI_TEXT_QUERY !== undefined) {
            textLimit.value = Number(limits.AI_TEXT_QUERY);
        }

        if (limits.AI_IMAGE_QUERY !== undefined) {
            imageLimit.value = Number(limits.AI_IMAGE_QUERY);
        }

        if (limits.AI_VIDEO_QUERY !== undefined) {
            videoLimit.value = Number(limits.AI_VIDEO_QUERY);
        }
    }

    const videoPoll = useAiVideoPoll({
        onLimitsUpdate: applyLimits,
        onError: ({ message }) => onVideoError?.(message),
        onDone: (task) => onVideoDone?.(task),
        resolveTask: (requestId) => videoHistory.value.find((task) => task.request_id === requestId) ?? null,
    });

    async function refreshLimits() {
        limitsLoading.value = true;

        try {
            const [textResponse, imageResponse, videoResponse] = await Promise.all([
                aiFetch("/panel/ai/limits", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ limit: "ai_text_query" }),
                }),
                aiFetch("/panel/ai/limits", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ limit: "ai_image_query" }),
                }),
                aiFetch("/panel/ai/limits", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ limit: "ai_video_query" }),
                }),
            ]);

            if (textResponse?.success) {
                textLimit.value = Number(textResponse.data ?? 0);
            }

            if (imageResponse?.success) {
                imageLimit.value = Number(imageResponse.data ?? 0);
            }

            if (videoResponse?.success) {
                videoLimit.value = Number(videoResponse.data ?? 0);
            }
        } finally {
            limitsLoading.value = false;
        }
    }

    async function sendRequest(payload) {
        loading.value = true;

        try {
            const response = await aiFetch("/panel/ai/marketplace", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload),
            });

            if (!response?.success) {
                return { ok: false, message: extractAiMessage(response, "Запрос не выполнен") };
            }

            applyLimits(response?.limits || response?.data?.limits);

            return { ok: true, response };
        } catch (error) {
            const status = error?.status;
            const payload = error?.payload || {};

            if (status === 402) {
                return {
                    ok: false,
                    message: extractAiMessage(payload, "Недостаточно лимитов для выполнения запроса"),
                    limitError: true,
                };
            }

            return {
                ok: false,
                message: extractAiMessage(payload, "Ошибка. Попробуйте позже."),
            };
        } finally {
            loading.value = false;
        }
    }

    async function runTextTask(payload) {
        const result = await sendRequest(payload);

        if (!result.ok) {
            return result;
        }

        const content = result.response?.content || result.response?.data?.content || "";

        if (payload?.task_type === "rich_description") {
            richDescriptionResult.value = content;
        } else {
            textResult.value = stripMarkdown(content);
        }

        return { ok: true };
    }

    async function runImageTask(payload) {
        const result = await sendRequest(payload);

        if (!result.ok) {
            return result;
        }

        const rawImages = result.response?.images || result.response?.data?.images || [];
        imageResults.value = Array.isArray(rawImages)
            ? rawImages.map(mapAiImageItem).filter(Boolean)
            : [];

        return { ok: true };
    }

    async function runVideoTask(payload) {
        loading.value = true;
        const body = buildVideoStartPayload(payload);

        try {
            const response = await aiFetch("/panel/ai/video/start", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(body),
            });

            if (!response?.success) {
                return { ok: false, message: extractAiMessage(response, "Запрос не выполнен") };
            }

            const reqId = response?.data?.request_id;
            if (reqId) {
                const task = {
                    request_id: reqId,
                    status: "pending",
                    prompt: body.prompt,
                    image: normalizeHistoryImage(body.image),
                    task_type: body.task_type,
                    duration: body.duration,
                    resolution: body.resolution,
                };
                videoHistory.value.unshift(task);
                videoPoll.start(reqId);
            }

            return { ok: true, requestId: reqId };
        } catch (error) {
            const status = error?.status;
            const payload = error?.payload || {};

            if (status === 402) {
                return {
                    ok: false,
                    message: extractAiMessage(payload, "Недостаточно лимита AI_VIDEO_QUERY"),
                    limitError: true,
                };
            }

            return {
                ok: false,
                message: extractAiMessage(payload, "Ошибка. Попробуйте позже."),
            };
        } finally {
            loading.value = false;
        }
    }

    async function runSceneVideoTask(payload) {
        loading.value = true;
        const body = buildSceneVideoPayload(payload);

        try {
            const response = await aiFetch("/panel/ai/video/reference/start", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(body),
            });

            if (!response?.success) {
                return { ok: false, message: extractAiMessage(response, "Запрос не выполнен") };
            }

            const reqId = response?.data?.request_id;
            if (reqId) {
                const task = {
                    request_id: reqId,
                    status: "pending",
                    prompt: body.prompt,
                    image: normalizeHistoryImage(body.images?.[0]),
                    images: normalizeHistoryImages(body.images),
                    task_type: "generate_video_from_scene",
                    duration: body.duration,
                    resolution: body.resolution,
                };
                videoHistory.value.unshift(task);
                videoPoll.start(reqId);
            }

            return { ok: true, requestId: reqId };
        } catch (error) {
            const status = error?.status;
            const payload = error?.payload || {};

            if (status === 402) {
                return {
                    ok: false,
                    message: extractAiMessage(payload, "Недостаточно лимита AI_VIDEO_QUERY"),
                    limitError: true,
                };
            }

            return {
                ok: false,
                message: extractAiMessage(payload, "Ошибка. Попробуйте позже."),
            };
        } finally {
            loading.value = false;
        }
    }

    return {
        loading,
        limitsLoading,
        textLimit,
        imageLimit,
        videoLimit,
        textResult,
        richDescriptionResult,
        imageResults,
        videoHistory,
        hasTextLimit,
        hasImageLimit,
        hasVideoLimit,
        refreshLimits,
        runTextTask,
        runImageTask,
        runVideoTask,
        runSceneVideoTask,
        stopVideoPolling: videoPoll.stop,
    };
}