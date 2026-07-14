<script setup>
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { History } from "lucide-vue-next";
import AiImageCanvas from "@/components/subscriber/ai/AiImageCanvas.vue";
import AiImageForm from "@/components/subscriber/ai/AiImageForm.vue";
import AiImageGalleryStrip from "@/components/subscriber/ai/AiImageGalleryStrip.vue";
import AiGenerationDeleteDialog from "@/components/subscriber/ai/AiGenerationDeleteDialog.vue";
import AiImageGenerationsStrip from "@/components/subscriber/ai/AiImageGenerationsStrip.vue";
import AiLimitsBadge from "@/components/subscriber/ai/AiLimitsBadge.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Button from "@/components/ui/Button.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { toAiMediaUrl } from "@/composables/useAiMediaUrl";
import { useFlashToast } from "@/composables/useFlashToast";
import { useMarketplaceAi } from "@/composables/useMarketplaceAi";
import { resolveImageForForm } from "@/utils/imageDataUrl";

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
        { label: "Изображения", href: "/panel/ai/image" },
    ];

    if (props.generationUuid) {
        items.push({ label: "Генерация" });
    }

    return items;
});

const imageFormRef = ref(null);
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
    imageLimit,
    imageHistory,
    savedGenerations,
    activeGenerationUuid,
    generationsLoading,
    openGeneration,
    deleteGeneration,
    loadGenerations,
    hasImageLimit,
    refreshLimits,
    runImageTask,
} = useMarketplaceAi(props.limits, { limitsMode: "image" });

function getTaskSourceUrls(task) {
    if (Array.isArray(task?.source_images) && task.source_images.length > 0) {
        return task.source_images;
    }

    if (task?.image) {
        return [task.image];
    }

    return [];
}

function getTaskResultUrls(task) {
    if (Array.isArray(task?.images) && task.images.length > 0) {
        return task.images;
    }

    return [];
}

const galleryItems = computed(() => {
    const items = [];
    const tasks = [...imageHistory.value].reverse();

    for (const task of tasks) {
        if (task.status !== "done") {
            continue;
        }

        const sourceUrls = getTaskSourceUrls(task);
        const resultUrls = getTaskResultUrls(task);
        const taskMeta = {
            taskId: task.id,
            prompt: task.prompt || "",
            aspectRatio: task.aspect_ratio || null,
            resolution: task.resolution || "default",
        };

        sourceUrls.forEach((url, index) => {
            items.push({
                id: `${task.id}-source-${index}`,
                kind: "source",
                imageIndex: index,
                url,
                allSourceUrls: sourceUrls,
                ...taskMeta,
            });
        });

        resultUrls.forEach((url, index) => {
            items.push({
                id: `${task.id}-result-${index}`,
                kind: "generated",
                imageIndex: index,
                url,
                allSourceUrls: sourceUrls,
                ...taskMeta,
            });
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

const canvasImage = computed(() => activeGalleryItem.value?.url || "");

const canvasLoading = computed(() => loading.value || activeGalleryItem.value?.status === "pending");

function resetWorkspace() {
    imageHistory.value = [];
    activeGalleryId.value = null;
    pendingGalleryItem.value = null;
    imageFormRef.value?.resetForm();
}

async function loadGenerationSession(uuid) {
    if (!uuid) {
        resetWorkspace();
        return;
    }

    const result = await openGeneration(uuid);
    if (!result.ok) {
        showError(result.message);
        router.visit("/panel/ai/image");
        return;
    }

    await nextTick();

    if (galleryItems.value.length > 0) {
        await selectLastGalleryItem();
    } else {
        activeGalleryId.value = null;
        imageFormRef.value?.resetForm();
    }
}

function handleOpenGeneration(generationUuid) {
    if (generationUuid === props.generationUuid) {
        return;
    }

    router.visit(`/panel/ai/image/${generationUuid}`);
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
        router.visit("/panel/ai/image");
    }
}

function resolveReferenceImages(urls = []) {
    return urls.map((url) => resolveImageForForm(url)).filter(Boolean);
}

async function seedFormFromGalleryItem(item) {
    if (!item || item.status === "pending" || !imageFormRef.value) {
        return;
    }

    seedingForm.value = true;

    try {
        const seed = {
            sourcePrompt: item.prompt || "",
            prompt: "",
            aspectRatio: item.aspectRatio,
            resolution: item.resolution,
            images: [],
        };

        if (item.kind === "source") {
            const referenceUrls = Array.isArray(item.allSourceUrls) && item.allSourceUrls.length > 0
                ? item.allSourceUrls
                : [item.url];
            seed.images = resolveReferenceImages(referenceUrls);
        } else {
            const formImage = resolveImageForForm(item.url);
            seed.images = formImage ? [formImage] : [];
        }

        imageFormRef.value.applySeed(seed);

        if (item.kind !== "source" && seed.images.length === 0) {
            showError("Не удалось подготовить изображение для редактирования");
        }
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
    const lastGenerated = [...galleryItems.value].reverse().find((item) => item.kind === "generated");
    const last = lastGenerated ?? galleryItems.value[galleryItems.value.length - 1];

    if (!last) {
        activeGalleryId.value = null;
        imageFormRef.value?.resetForm();
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

function buildPendingPreviewUrl(snapshot, fallbackUrl = "") {
    const firstImage = snapshot?.images?.[0];
    if (firstImage) {
        return toAiMediaUrl(firstImage, { allowDataUrl: true }) || fallbackUrl;
    }

    return fallbackUrl;
}

async function handleImageSubmit(payload) {
    const resolutionCostMap = { default: 1, "1K": 2, "2K": 3, "4K": 3 };
    const totalCost = resolutionCostMap[payload?.resolution] || 1;
    const currentLimit = Number(imageLimit.value ?? 0);

    if (!hasImageLimit.value || currentLimit < totalCost) {
        showError(`Недостаточно лимитов AI_IMAGE_QUERY (нужно ${totalCost}, доступно ${currentLimit})`);
        return;
    }

    const rollbackSnapshot = imageFormRef.value?.getSnapshot?.() ?? null;
    const previousActiveGalleryId = activeGalleryId.value;
    const pendingId = `pending-${Date.now()}`;
    const previewUrl = buildPendingPreviewUrl(rollbackSnapshot, activeGalleryItem.value?.url || "");

    pendingGalleryItem.value = {
        id: pendingId,
        kind: "generated",
        status: "pending",
        url: previewUrl,
        prompt: rollbackSnapshot?.sourcePrompt || rollbackSnapshot?.prompt || "",
        aspectRatio: rollbackSnapshot?.aspectRatio ?? null,
        resolution: rollbackSnapshot?.resolution || "default",
    };
    activeGalleryId.value = pendingId;

    await nextTick();
    scrollGalleryToBottom();

    const result = await runImageTask(payload);
    pendingGalleryItem.value = null;

    if (!result.ok) {
        if (rollbackSnapshot) {
            imageFormRef.value?.applySnapshot?.(rollbackSnapshot);
        }

        activeGalleryId.value = previousActiveGalleryId;
        showError(result.message);
        return;
    }

    showSuccess("Готово");

    if (!props.generationUuid && result.generationUuid) {
        router.visit(`/panel/ai/image/${result.generationUuid}`);
        return;
    }

    await nextTick();
    await selectLastGalleryItem();
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
    <Head title="ИИ — Изображения" />

    <SubscriberLayout title="ИИ — Изображения" :breadcrumbs="breadcrumbs">
        <div class="flex h-[calc(100dvh-10.5rem)] max-h-[calc(100dvh-10.5rem)] flex-col overflow-hidden">
            <ToolPageHeader
                title="Генерация изображений"
                description="Создавайте и дорабатывайте визуалы"
                class="!mb-3 shrink-0"
            >
                <template #actions>
                    <Button href="/panel/ai/image/history" variant="outline" size="sm" class="h-8 gap-1.5 text-xs">
                        <History class="h-3.5 w-3.5" />
                        История
                    </Button>
                    <AiLimitsBadge mode="image" :loading="limitsLoading" :image-limit="imageLimit" />
                </template>
            </ToolPageHeader>

            <div v-if="limitsLoading" class="min-h-0 flex-1">
                <Skeleton class="h-full min-h-[240px] w-full rounded-2xl" />
            </div>

            <template v-else>
                <div class="flex min-h-0 flex-1 flex-col gap-3">
                    <AiImageGenerationsStrip
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
                            <AiImageCanvas :image="canvasImage" :loading="canvasLoading" />
                        </div>

                        <div
                            v-if="displayGalleryItems.length > 0"
                            ref="galleryScrollRef"
                            class="h-full shrink-0 overflow-y-auto pr-0.5"
                        >
                            <AiImageGalleryStrip
                                :items="displayGalleryItems"
                                :active-id="activeGalleryId"
                                @select="handleGallerySelect"
                            />
                        </div>
                    </div>

                    <AiImageForm
                        ref="imageFormRef"
                        class="shrink-0"
                        :loading="loading || seedingForm"
                        :disabled="!hasImageLimit"
                        @submit="handleImageSubmit"
                        @error="showError"
                    />
                </div>
            </template>
        </div>
        <AiGenerationDeleteDialog
            :open="deleteDialogOpen"
            media-label="изображения"
            :loading="Boolean(deletingGenerationUuid)"
            @update:open="handleDeleteDialogOpen"
            @confirm="confirmDeleteGeneration"
        />
    </SubscriberLayout>
</template>