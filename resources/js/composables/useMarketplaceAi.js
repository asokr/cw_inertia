import { computed, ref } from "vue";
import { aiFetch, extractAiMessage } from "@/composables/useAiGeneration";
import { mapAiImageItem, normalizeVideoItem, toAiMediaUrl } from "@/composables/useAiMediaUrl";
import { useAiVideoPoll } from "@/composables/useAiVideoPoll";

const GENERATION_STORAGE_KEYS = {
    image: "ai_image_active_generation_id",
    video: "ai_video_active_generation_id",
};

const GENERATIONS_BASE_PATHS = {
    image: "/panel/ai/image/generations",
    video: "/panel/ai/video/generations",
};

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

function pickPreferredImageTaskId(tasks = []) {
    if (!Array.isArray(tasks) || tasks.length === 0) {
        return null;
    }

    const doneWithImages = tasks.find((task) => task.status === "done" && Array.isArray(task.images) && task.images.length > 0);
    if (doneWithImages?.id) {
        return doneWithImages.id;
    }

    return tasks[0]?.id ?? null;
}

function mapApiImageTask(task) {
    if (!task || typeof task !== "object" || !task.id) {
        return null;
    }

    const mapped = {
        id: task.id,
        status: task.status,
        prompt: task.prompt,
        task_type: task.task_type,
        image_variants: task.image_variants,
        resolution: task.resolution,
        aspect_ratio: task.aspect_ratio,
        error: task.error,
        created_at: task.created_at,
    };

    if (task.image) {
        mapped.image = normalizeHistoryImage(task.image);
    }

    if (Array.isArray(task.images) && task.images.length > 0) {
        mapped.images = normalizeHistoryImages(task.images);
    }

    return mapped;
}

function pickPreferredVideoTaskId(tasks = []) {
    if (!Array.isArray(tasks) || tasks.length === 0) {
        return null;
    }

    const doneWithVideo = tasks.find((task) => task.status === "done" && task.video?.url);
    if (doneWithVideo?.request_id) {
        return doneWithVideo.request_id;
    }

    const pending = tasks.find((task) => task.status === "pending");
    if (pending?.request_id) {
        return pending.request_id;
    }

    return tasks[0]?.request_id ?? null;
}

function mapApiTask(task) {
    if (!task || typeof task !== "object") {
        return null;
    }

    const mapped = {
        request_id: task.request_id,
        status: task.status,
        prompt: task.prompt,
        task_type: task.task_type,
        duration: task.duration,
        resolution: task.resolution,
        aspect_ratio: task.aspect_ratio,
        error: task.error,
    };

    if (task.image) {
        mapped.image = normalizeHistoryImage(task.image);
    }

    if (Array.isArray(task.images) && task.images.length > 0) {
        mapped.images = normalizeHistoryImages(task.images);
    }

    const video = normalizeVideoItem(task.video);
    if (video) {
        mapped.video = video;
    }

    return mapped.request_id ? mapped : null;
}

function buildVideoStartPayload(payload, generationId) {
    const body = {
        task_type: payload.task_type,
        prompt: String(payload.prompt || "").trim(),
        duration: Number(payload.duration || 5),
        resolution: payload.resolution || "480p",
    };

    if (generationId) {
        body.generation_id = generationId;
    }

    if (payload.task_type === "generate_video" && payload.aspect_ratio) {
        body.aspect_ratio = payload.aspect_ratio;
    }

    if (payload.task_type === "generate_video_from_image" && payload.image) {
        body.image = payload.image;
    }

    return body;
}

function buildSceneVideoPayload(payload, generationId) {
    const body = {
        prompt: String(payload.prompt || "").trim(),
        duration: Number(payload.duration || 5),
        resolution: payload.resolution || "480p",
        images: Array.isArray(payload.images) ? payload.images : [],
    };

    if (generationId) {
        body.generation_id = generationId;
    }

    return body;
}

export function useMarketplaceAi(initialLimits = {}, { onVideoError, onVideoDone, limitsMode = "all" } = {}) {
    const loading = ref(false);
    const limitsLoading = ref(false);
    const generationsLoading = ref(false);

    const textLimit = ref(initialLimits.text ?? 0);
    const imageLimit = ref(initialLimits.image ?? 0);
    const videoLimit = ref(initialLimits.video ?? 0);

    const textResult = ref("");
    const richDescriptionResult = ref("");
    const imageResults = ref([]);
    const imageHistory = ref([]);
    const videoHistory = ref([]);
    const savedGenerations = ref([]);
    const activeGenerationId = ref(null);

    const generationsMode = limitsMode === "image" || limitsMode === "video" ? limitsMode : "video";
    const generationsBasePath = GENERATIONS_BASE_PATHS[generationsMode];
    const activeGenerationStorageKey = GENERATION_STORAGE_KEYS[generationsMode];
    const taskHistory = generationsMode === "image" ? imageHistory : videoHistory;

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

    function rememberActiveGeneration(id) {
        activeGenerationId.value = id;
        if (id) {
            localStorage.setItem(activeGenerationStorageKey, String(id));
        } else {
            localStorage.removeItem(activeGenerationStorageKey);
        }
    }

    function upsertGenerationSummary(generation) {
        if (!generation?.id) {
            return;
        }

        const index = savedGenerations.value.findIndex((item) => item.id === generation.id);
        if (index === -1) {
            savedGenerations.value.unshift(generation);
            return;
        }

        savedGenerations.value[index] = {
            ...savedGenerations.value[index],
            ...generation,
        };
    }

    const videoPoll = useAiVideoPoll({
        onLimitsUpdate: applyLimits,
        onError: ({ message }) => onVideoError?.(message),
        onDone: (task) => {
            onVideoDone?.(task);
            void loadGenerations();
        },
        resolveTask: (requestId) => videoHistory.value.find((task) => task.request_id === requestId) ?? null,
    });

    async function refreshLimits(mode = limitsMode) {
        limitsLoading.value = true;

        const requests = [];

        if (mode === "all" || mode === "text") {
            requests.push(
                aiFetch("/panel/ai/limits", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ limit: "ai_text_query" }),
                }).then((response) => ({ type: "text", response })),
            );
        }

        if (mode === "all" || mode === "image") {
            requests.push(
                aiFetch("/panel/ai/limits", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ limit: "ai_image_query" }),
                }).then((response) => ({ type: "image", response })),
            );
        }

        if (mode === "all" || mode === "video") {
            requests.push(
                aiFetch("/panel/ai/limits", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ limit: "ai_video_query" }),
                }).then((response) => ({ type: "video", response })),
            );
        }

        try {
            const results = await Promise.all(requests);

            for (const { type, response } of results) {
                if (!response?.success) {
                    continue;
                }

                if (type === "text") {
                    textLimit.value = Number(response.data ?? 0);
                }

                if (type === "image") {
                    imageLimit.value = Number(response.data ?? 0);
                }

                if (type === "video") {
                    videoLimit.value = Number(response.data ?? 0);
                }
            }
        } finally {
            limitsLoading.value = false;
        }
    }

    async function loadGenerations() {
        if (limitsMode !== "image" && limitsMode !== "video") {
            return;
        }

        generationsLoading.value = true;

        try {
            const response = await aiFetch(generationsBasePath);
            if (response?.success) {
                savedGenerations.value = Array.isArray(response.data) ? response.data : [];
            }
        } finally {
            generationsLoading.value = false;
        }
    }

    async function openGeneration(generationId) {
        if (!generationId) {
            return { ok: false, message: "Генерация не выбрана" };
        }

        try {
            const response = await aiFetch(`${generationsBasePath}/${generationId}`);
            if (!response?.success) {
                return { ok: false, message: extractAiMessage(response, "Не удалось загрузить генерацию") };
            }

            const mapTask = generationsMode === "image" ? mapApiImageTask : mapApiTask;
            const tasks = Array.isArray(response.data?.tasks)
                ? response.data.tasks.map(mapTask).filter(Boolean)
                : [];

            taskHistory.value = tasks;
            rememberActiveGeneration(generationId);

            if (generationsMode === "video") {
                videoPoll.resumePending(tasks);
            }

            return {
                ok: true,
                tasks,
                preferredTaskId: generationsMode === "image"
                    ? pickPreferredImageTaskId(tasks)
                    : pickPreferredVideoTaskId(tasks),
            };
        } catch (error) {
            return {
                ok: false,
                message: extractAiMessage(error?.payload, "Не удалось загрузить генерацию"),
            };
        }
    }

    async function createGeneration() {
        try {
            const response = await aiFetch(generationsBasePath, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({}),
            });

            if (!response?.success) {
                return { ok: false, message: extractAiMessage(response, "Не удалось создать генерацию") };
            }

            const generation = {
                ...response.data,
                tasks_count: 0,
                has_pending: false,
            };
            upsertGenerationSummary(generation);
            taskHistory.value = [];
            rememberActiveGeneration(generation.id);

            return { ok: true, generation };
        } catch (error) {
            return {
                ok: false,
                message: extractAiMessage(error?.payload, "Не удалось создать генерацию"),
            };
        }
    }

    async function deleteGeneration(generationId) {
        try {
            const response = await aiFetch(`${generationsBasePath}/${generationId}`, {
                method: "DELETE",
            });

            if (!response?.success) {
                return { ok: false, message: extractAiMessage(response, "Не удалось удалить генерацию") };
            }

            savedGenerations.value = savedGenerations.value.filter((item) => item.id !== generationId);

            if (activeGenerationId.value === generationId) {
                taskHistory.value = [];
                rememberActiveGeneration(null);
            }

            return { ok: true };
        } catch (error) {
            return {
                ok: false,
                message: extractAiMessage(error?.payload, "Не удалось удалить генерацию"),
            };
        }
    }

    async function restoreActiveGeneration() {
        if (limitsMode !== "image" && limitsMode !== "video") {
            return { ok: true, tasks: [] };
        }

        await loadGenerations();

        if (savedGenerations.value.length === 0) {
            return { ok: true, tasks: [] };
        }

        const storedId = Number(localStorage.getItem(activeGenerationStorageKey) || 0);
        const target = savedGenerations.value.find((item) => item.id === storedId)
            ?? savedGenerations.value[0];

        if (!target?.id) {
            return { ok: true, tasks: [] };
        }

        return openGeneration(target.id);
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
        loading.value = true;

        const body = {
            ...payload,
            generation_id: activeGenerationId.value || undefined,
        };

        try {
            const response = await aiFetch("/panel/ai/image/start", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(body),
            });

            if (!response?.success) {
                return {
                    ok: false,
                    message: extractAiMessage(response, "Запрос не выполнен"),
                    moderation: Boolean(response?.meta?.moderation),
                    generationId: response?.data?.generation_id ?? null,
                };
            }

            applyLimits(response?.limits || response?.data?.limits);

            const generationId = response?.data?.generation_id;
            if (generationId) {
                rememberActiveGeneration(generationId);
            }

            const mappedTask = mapApiImageTask(response?.data?.task);
            if (mappedTask) {
                imageHistory.value.unshift(mappedTask);
            }

            const rawImages = response?.images
                || mappedTask?.images
                || response?.data?.task?.images
                || [];
            imageResults.value = Array.isArray(rawImages)
                ? rawImages.map(mapAiImageItem).filter(Boolean)
                : [];

            await loadGenerations();

            return {
                ok: true,
                taskId: mappedTask?.id ?? null,
                generationId,
            };
        } catch (error) {
            const status = error?.status;
            const errorPayload = error?.payload || {};

            if (status === 402) {
                return {
                    ok: false,
                    message: extractAiMessage(errorPayload, "Недостаточно лимита AI_IMAGE_QUERY"),
                    limitError: true,
                };
            }

            return {
                ok: false,
                message: extractAiMessage(errorPayload, "Ошибка. Попробуйте позже."),
            };
        } finally {
            loading.value = false;
        }
    }

    async function runVideoTask(payload) {
        loading.value = true;
        const body = buildVideoStartPayload(payload, activeGenerationId.value);

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
            const generationId = response?.data?.generation_id;

            if (generationId) {
                rememberActiveGeneration(generationId);
            }

            if (reqId) {
                const task = {
                    request_id: reqId,
                    status: "pending",
                    prompt: body.prompt,
                    image: normalizeHistoryImage(body.image),
                    task_type: body.task_type,
                    duration: body.duration,
                    resolution: body.resolution,
                    aspect_ratio: body.aspect_ratio,
                };
                videoHistory.value.unshift(task);
                videoPoll.start(reqId);
            }

            await loadGenerations();

            return { ok: true, requestId: reqId, generationId };
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
        const body = buildSceneVideoPayload(payload, activeGenerationId.value);

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
            const generationId = response?.data?.generation_id;

            if (generationId) {
                rememberActiveGeneration(generationId);
            }

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

            await loadGenerations();

            return { ok: true, requestId: reqId, generationId };
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
        generationsLoading,
        textLimit,
        imageLimit,
        videoLimit,
        textResult,
        richDescriptionResult,
        imageResults,
        imageHistory,
        videoHistory,
        savedGenerations,
        activeGenerationId,
        hasTextLimit,
        hasImageLimit,
        hasVideoLimit,
        refreshLimits,
        loadGenerations,
        openGeneration,
        createGeneration,
        deleteGeneration,
        restoreActiveGeneration,
        runTextTask,
        runImageTask,
        runVideoTask,
        runSceneVideoTask,
        stopVideoPolling: videoPoll.stop,
    };
}