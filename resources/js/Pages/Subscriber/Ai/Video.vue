<script setup>
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { History } from "lucide-vue-next";
import AiGenerationDeleteDialog from "@/components/subscriber/ai/AiGenerationDeleteDialog.vue";
import AiLimitsBadge from "@/components/subscriber/ai/AiLimitsBadge.vue";
import AiVideoCanvas from "@/components/subscriber/ai/AiVideoCanvas.vue";
import AiVideoForm from "@/components/subscriber/ai/AiVideoForm.vue";
import AiVideoGalleryStrip from "@/components/subscriber/ai/AiVideoGalleryStrip.vue";
import AiVideoGenerationsStrip from "@/components/subscriber/ai/AiVideoGenerationsStrip.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Button from "@/components/ui/Button.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { normalizeVideoItem, toAiMediaUrl } from "@/composables/useAiMediaUrl";
import { useFlashToast } from "@/composables/useFlashToast";
import { useMarketplaceAi } from "@/composables/useMarketplaceAi";
import { urlToDataUrl } from "@/utils/imageDataUrl";

const props = defineProps({
    limits: {
        type: Object,
        default: () => ({ text: 0, image: 0, video: 0 }),
    },
    generationUuid: {
        type: String,
        default: null,
    },
});

const breadcrumbs = computed(() => {
    const items = [
        { label: "Главная", href: "/panel" },
        { label: "ИИ Инструменты", href: "/panel/ai/text" },
        { label: "Видео", href: "/panel/ai/video" },
    ];

    if (props.generationUuid) {
        items.push({ label: "Генерация" });
    }

    return items;
});

const videoFormRef = ref(null);
const galleryScrollRef = ref(null);
const activeGalleryId = ref(null);
const pendingGalleryItem = ref(null);
const seedingForm = ref(false);
const deletingGenerationUuid = ref(null);
const deleteDialogOpen = ref(false);
const pendingDeleteUuid = ref(null);
const isDraftMode = computed(() => !props.generationUuid);
const { showError, showSuccess } = useFlashToast();

const {
    loading,
    limitsLoading,
    videoLimit,
    videoHistory,
    savedGenerations,
    activeGenerationUuid,
    generationsLoading,
    openGeneration,
    deleteGeneration,
    loadGenerations,
    hasVideoLimit,
    refreshLimits,
    runVideoTask,
    runSceneVideoTask,
} = useMarketplaceAi(props.limits, {
    limitsMode: "video",
    onVideoError: (message) => showError(message),
    onVideoDone: async () => {
        showSuccess("Видео готово");
        await nextTick();
        await selectLastGalleryItem();
        scrollGalleryToBottom();
    },
});

function getTaskPosterUrl(task) {
    if (task?.status === "done") {
        const videoUrl = normalizeVideoItem(task.video)?.url;
        if (videoUrl) {
            return { url: "", videoUrl };
        }
    }

    if (task?.image) {
        return { url: task.image, videoUrl: "" };
    }

    if (Array.isArray(task?.images) && task.images.length > 0) {
        return { url: task.images[0], videoUrl: "" };
    }

    return { url: "", videoUrl: "" };
}

const galleryItems = computed(() => {
    const items = [];
    const tasks = [...videoHistory.value].reverse();

    for (const task of tasks) {
        if (!task?.request_id) {
            continue;
        }

        const poster = getTaskPosterUrl(task);

        items.push({
            id: task.request_id,
            status: task.status,
            url: poster.url,
            videoUrl: poster.videoUrl,
            prompt: task.prompt || "",
            task_type: task.task_type || "generate_video",
            duration: task.duration || 5,
            resolution: task.resolution || "480p",
            aspect_ratio: task.aspect_ratio || "16:9",
            image: task.image || "",
            images: Array.isArray(task.images) ? [...task.images] : [],
            task,
        });
    }

    return items;
});

const displayGalleryItems = computed(() => {
    if (!pendingGalleryItem.value) {
        return galleryItems.value;
    }

    return [...galleryItems.value, pendingGalleryItem.value];
});

const activeGalleryItem = computed(() =>
    displayGalleryItems.value.find((item) => item.id === activeGalleryId.value) ?? null,
);

const activeCanvasTask = computed(() => {
    if (activeGalleryItem.value?.task) {
        return activeGalleryItem.value.task;
    }

    if (activeGalleryItem.value?.status === "pending" && !activeGalleryItem.value?.task) {
        return {
            request_id: activeGalleryItem.value.id,
            status: "pending",
            prompt: activeGalleryItem.value.prompt,
            image: activeGalleryItem.value.url,
            images: activeGalleryItem.value.images,
            task_type: activeGalleryItem.value.task_type,
            duration: activeGalleryItem.value.duration,
            resolution: activeGalleryItem.value.resolution,
            aspect_ratio: activeGalleryItem.value.aspect_ratio,
        };
    }

    return activeGalleryItem.value?.task ?? null;
});

const canvasLoading = computed(() =>
    loading.value
    || activeGalleryItem.value?.status === "pending"
    || activeCanvasTask.value?.status === "pending",
);

function resetWorkspace() {
    videoHistory.value = [];
    activeGalleryId.value = null;
    pendingGalleryItem.value = null;
    videoFormRef.value?.resetForm();
}

async function loadGenerationSession(uuid) {
    if (!uuid) {
        resetWorkspace();
        return;
    }

    const result = await openGeneration(uuid);
    if (!result.ok) {
        showError(result.message);
        router.visit("/panel/ai/video");
        return;
    }

    await nextTick();

    if (galleryItems.value.length > 0) {
        await selectLastGalleryItem();
    } else {
        activeGalleryId.value = null;
        videoFormRef.value?.resetForm();
    }
}

function handleOpenGeneration(generationUuid) {
    if (generationUuid === props.generationUuid) {
        return;
    }

    router.visit(`/panel/ai/video/${generationUuid}`);
}

function handleDeleteGeneration(generationUuid) {
    pendingDeleteUuid.value = generationUuid;
    deleteDialogOpen.value = true;
}

function handleDeleteDialogOpen(value) {
    deleteDialogOpen.value = value;

    if (!value && !deletingGenerationUuid.value) {
        pendingDeleteUuid.value = null;
    }
}

async function confirmDeleteGeneration() {
    const generationUuid = pendingDeleteUuid.value;
    if (!generationUuid) {
        return;
    }

    deletingGenerationUuid.value = generationUuid;
    const result = await deleteGeneration(generationUuid);
    deletingGenerationUuid.value = null;

    if (!result.ok) {
        showError(result.message);
        return;
    }

    deleteDialogOpen.value = false;
    pendingDeleteUuid.value = null;
    showSuccess("Сессия удалена");

    if (props.generationUuid === generationUuid) {
        router.visit("/panel/ai/video");
    }
}

async function resolveReferenceImages(urls = []) {
    const resolved = [];

    for (const url of urls) {
        try {
            const dataUrl = await urlToDataUrl(url);
            if (dataUrl) {
                resolved.push(dataUrl);
            }
        } catch {
            // skip broken reference
        }
    }

    return resolved;
}

async function seedFormFromGalleryItem(item) {
    if (!item || item.status === "pending" || !videoFormRef.value) {
        return;
    }

    seedingForm.value = true;

    try {
        const seed = {
            sourcePrompt: item.prompt || "",
            prompt: "",
            task_type: item.task_type || "generate_video",
            duration: item.duration || 5,
            resolution: item.resolution || "480p",
            aspect_ratio: item.aspect_ratio || "16:9",
            image: "",
            images: [],
        };

        if (item.task_type === "generate_video_from_image" && item.image) {
            const dataUrl = await urlToDataUrl(item.image);
            seed.image = dataUrl || "";
        } else if (item.task_type === "generate_video_from_scene") {
            const refs = Array.isArray(item.images) && item.images.length > 0
                ? item.images
                : (item.image ? [item.image] : []);
            seed.images = await resolveReferenceImages(refs);
        }

        videoFormRef.value.applySeed(seed);
    } catch {
        videoFormRef.value.applySeed({
            sourcePrompt: item.prompt || "",
            prompt: "",
            task_type: item.task_type,
            duration: item.duration,
            resolution: item.resolution,
            aspect_ratio: item.aspect_ratio,
            image: "",
            images: [],
        });
        showError("Не удалось подготовить данные для повторной генерации");
    } finally {
        seedingForm.value = false;
    }
}

async function handleGallerySelect(item) {
    if (item.status === "pending") {
        return;
    }

    activeGalleryId.value = item.id;
    await seedFormFromGalleryItem(item);
}

async function selectLastGalleryItem() {
    const last = galleryItems.value[galleryItems.value.length - 1];

    if (!last) {
        activeGalleryId.value = null;
        videoFormRef.value?.resetForm();
        return;
    }

    activeGalleryId.value = last.id;
    await seedFormFromGalleryItem(last);
}

function scrollGalleryToBottom() {
    const container = galleryScrollRef.value;
    if (!container) {
        return;
    }

    container.scrollTop = container.scrollHeight;
}

function buildPendingPreviewUrl(snapshot) {
    if (snapshot?.image) {
        return toAiMediaUrl(snapshot.image, { allowDataUrl: true }) || "";
    }

    const firstImage = snapshot?.images?.[0];
    if (firstImage) {
        return toAiMediaUrl(firstImage, { allowDataUrl: true }) || "";
    }

    return "";
}

async function handleVideoSubmit(payload) {
    const duration = Number(payload?.duration || 5);
    const resMultiplier = payload?.resolution === "720p" ? 2 : 1;
    const totalCost = duration * resMultiplier;
    const currentLimit = Number(videoLimit.value ?? 0);

    if (!hasVideoLimit.value || currentLimit < totalCost) {
        showError(`Недостаточно лимитов AI_VIDEO_QUERY (нужно ${totalCost}, доступно ${currentLimit})`);
        return;
    }

    const rollbackSnapshot = videoFormRef.value?.getSnapshot?.() ?? null;
    const previousActiveGalleryId = activeGalleryId.value;
    const pendingId = `pending-${Date.now()}`;
    const previewUrl = buildPendingPreviewUrl(rollbackSnapshot);

    pendingGalleryItem.value = {
        id: pendingId,
        status: "pending",
        url: previewUrl,
        videoUrl: "",
        prompt: rollbackSnapshot?.sourcePrompt || rollbackSnapshot?.prompt || "",
        task_type: rollbackSnapshot?.task_type || "generate_video",
        duration: rollbackSnapshot?.duration || 5,
        resolution: rollbackSnapshot?.resolution || "480p",
        aspect_ratio: rollbackSnapshot?.aspect_ratio || "16:9",
        image: rollbackSnapshot?.image || "",
        images: Array.isArray(rollbackSnapshot?.images) ? [...rollbackSnapshot.images] : [],
    };
    activeGalleryId.value = pendingId;

    await nextTick();
    scrollGalleryToBottom();

    let result;
    if (payload.task_type === "generate_video_from_scene") {
        result = await runSceneVideoTask(payload);
    } else {
        result = await runVideoTask(payload);
    }

    pendingGalleryItem.value = null;

    if (!result.ok) {
        if (rollbackSnapshot) {
            videoFormRef.value?.applySnapshot?.(rollbackSnapshot);
        }

        activeGalleryId.value = previousActiveGalleryId;
        showError(result.message);
        return;
    }

    showSuccess("Генерация видео запущена");

    if (!props.generationUuid && result.generationUuid) {
        router.visit(`/panel/ai/video/${result.generationUuid}`);
        return;
    }

    if (result.requestId) {
        activeGalleryId.value = result.requestId;
    }

    await nextTick();
    scrollGalleryToBottom();
}

onMounted(async () => {
    await refreshLimits();
    await loadGenerations();

    if (props.generationUuid) {
        await loadGenerationSession(props.generationUuid);
    } else {
        resetWorkspace();
    }
});

watch(
    () => props.generationUuid,
    async (uuid) => {
        if (uuid) {
            await loadGenerationSession(uuid);
        } else {
            resetWorkspace();
        }
    },
);
</script>

<template>
    <Head title="ИИ — Видео" />

    <SubscriberLayout title="ИИ — Видео" :breadcrumbs="breadcrumbs">
        <div class="flex h-[calc(100dvh-10.5rem)] max-h-[calc(100dvh-10.5rem)] flex-col overflow-hidden">
            <ToolPageHeader
                title="Генерация видео"
                description="Text-to-video, анимация изображений и сцены по референсам"
                class="!mb-3 shrink-0"
            >
                <template #actions>
                    <Button href="/panel/ai/video/history" variant="outline" size="sm" class="h-8 gap-1.5 text-xs">
                        <History class="h-3.5 w-3.5" />
                        История
                    </Button>
                    <AiLimitsBadge mode="video" :loading="limitsLoading" :video-limit="videoLimit" />
                </template>
            </ToolPageHeader>

            <div v-if="limitsLoading" class="min-h-0 flex-1">
                <Skeleton class="h-full min-h-[240px] w-full rounded-2xl" />
            </div>

            <template v-else>
                <div class="flex min-h-0 flex-1 flex-col gap-3">
                    <AiVideoGenerationsStrip
                        class="shrink-0"
                        :items="savedGenerations"
                        :active-id="activeGenerationUuid"
                        :loading="generationsLoading"
                        :deleting-id="deletingGenerationUuid"
                        :is-draft="isDraftMode"
                        @open="handleOpenGeneration"
                        @delete="handleDeleteGeneration"
                    />

                    <div class="flex min-h-0 flex-1 gap-3 lg:gap-4">
                        <div class="flex min-h-0 min-w-0 flex-1">
                            <AiVideoCanvas :task="activeCanvasTask" :loading="canvasLoading" />
                        </div>

                        <div
                            v-if="displayGalleryItems.length > 0"
                            ref="galleryScrollRef"
                            class="h-full shrink-0 overflow-y-auto pr-0.5"
                        >
                            <AiVideoGalleryStrip
                                :items="displayGalleryItems"
                                :active-id="activeGalleryId"
                                @select="handleGallerySelect"
                            />
                        </div>
                    </div>

                    <AiVideoForm
                        ref="videoFormRef"
                        class="shrink-0"
                        :loading="loading || seedingForm"
                        :disabled="!hasVideoLimit"
                        @submit="handleVideoSubmit"
                        @error="showError"
                    />
                </div>
            </template>
        </div>
        <AiGenerationDeleteDialog
            :open="deleteDialogOpen"
            media-label="видео"
            :loading="Boolean(deletingGenerationUuid)"
            @update:open="handleDeleteDialogOpen"
            @confirm="confirmDeleteGeneration"
        />
    </SubscriberLayout>
</template>