<script setup>
import { computed, nextTick, onMounted, ref } from "vue";
import { Head } from "@inertiajs/vue3";
import { History } from "lucide-vue-next";
import AiImageCanvas from "@/components/subscriber/ai/AiImageCanvas.vue";
import AiImageForm from "@/components/subscriber/ai/AiImageForm.vue";
import AiImageGalleryStrip from "@/components/subscriber/ai/AiImageGalleryStrip.vue";
import AiLimitsBadge from "@/components/subscriber/ai/AiLimitsBadge.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Button from "@/components/ui/Button.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { toAiMediaUrl } from "@/composables/useAiMediaUrl";
import { useFlashToast } from "@/composables/useFlashToast";
import { useMarketplaceAi } from "@/composables/useMarketplaceAi";
import { urlToDataUrl } from "@/utils/imageDataUrl";

const props = defineProps({
    limits: {
        type: Object,
        default: () => ({ text: 0, image: 0, video: 0 }),
    },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "ИИ Инструменты", href: "/panel/ai/text" },
    { label: "Изображения" },
];

const imageFormRef = ref(null);
const galleryScrollRef = ref(null);
const activeGalleryId = ref(null);
const pendingGalleryItem = ref(null);
const seedingForm = ref(false);
const { showError, showSuccess } = useFlashToast();

const {
    loading,
    limitsLoading,
    imageLimit,
    imageHistory,
    openGeneration,
    restoreActiveGeneration,
    hasImageLimit,
    refreshLimits,
    runImageTask,
} = useMarketplaceAi(props.limits, { limitsMode: "image" });

const galleryItems = computed(() => {
    const items = [];
    const tasks = [...imageHistory.value].reverse();

    for (const task of tasks) {
        if (task.status !== "done") {
            continue;
        }

        const urls = Array.isArray(task.images) && task.images.length > 0
            ? task.images
            : (task.image ? [task.image] : []);

        urls.forEach((url, index) => {
            items.push({
                id: `${task.id}-${index}`,
                taskId: task.id,
                imageIndex: index,
                url,
                prompt: task.prompt || "",
                aspectRatio: task.aspect_ratio || "3:4",
                resolution: task.resolution || "default",
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

const canvasImage = computed(() => {
    if (activeGalleryItem.value?.status === "pending") {
        return activeGalleryItem.value?.url || "";
    }

    return activeGalleryItem.value?.url || "";
});

const canvasLoading = computed(() => loading.value || activeGalleryItem.value?.status === "pending");

function resolveGenerationIdFromQuery() {
    const raw = new URLSearchParams(window.location.search).get("generation");
    const id = Number(raw || 0);

    return Number.isFinite(id) && id > 0 ? id : null;
}

async function seedFormFromGalleryItem(item) {
    if (!item || item.status === "pending" || !imageFormRef.value) {
        return;
    }

    seedingForm.value = true;

    try {
        const dataUrl = await urlToDataUrl(item.url);

        imageFormRef.value.applySeed({
            sourcePrompt: item.prompt || "",
            prompt: "",
            aspectRatio: item.aspectRatio,
            resolution: item.resolution,
            images: dataUrl ? [dataUrl] : [],
        });
    } catch {
        imageFormRef.value.applySeed({
            sourcePrompt: item.prompt || "",
            prompt: "",
            aspectRatio: item.aspectRatio,
            resolution: item.resolution,
            images: [],
        });
        showError("Не удалось подготовить изображение для редактирования");
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
        status: "pending",
        url: previewUrl,
        prompt: rollbackSnapshot?.sourcePrompt || rollbackSnapshot?.prompt || "",
        aspectRatio: rollbackSnapshot?.aspectRatio || "3:4",
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
    await nextTick();
    await selectLastGalleryItem();
    scrollGalleryToBottom();
}

onMounted(async () => {
    await refreshLimits();

    const generationId = resolveGenerationIdFromQuery();
    if (generationId) {
        const result = await openGeneration(generationId);
        if (!result.ok) {
            showError(result.message);
        }
    } else {
        await restoreActiveGeneration();
    }

    await nextTick();

    if (galleryItems.value.length > 0) {
        await selectLastGalleryItem();
    }
});
</script>

<template>
    <Head title="ИИ — Изображения" />

    <SubscriberLayout title="ИИ — Изображения" :breadcrumbs="breadcrumbs">
        <ToolPageHeader title="Генерация изображений" description="Создавайте и дорабатывайте визуалы — выберите превью справа, чтобы продолжить с этого кадра">
            <template #actions>
                <Button href="/panel/ai/image/history" variant="outline" size="sm" class="h-8 gap-1.5 text-xs">
                    <History class="h-3.5 w-3.5" />
                    История
                </Button>
                <AiLimitsBadge mode="image" :loading="limitsLoading" :image-limit="imageLimit" />
            </template>
        </ToolPageHeader>

        <div v-if="limitsLoading" class="mt-6 space-y-4">
            <Skeleton class="h-[560px] w-full rounded-2xl" />
        </div>

        <template v-else>
            <div class="mt-5">
                <div class="flex gap-3 lg:gap-4">
                    <div class="flex min-w-0 flex-1 flex-col gap-4">
                        <AiImageCanvas :image="canvasImage" :loading="canvasLoading" />
                        <AiImageForm
                            ref="imageFormRef"
                            :loading="loading || seedingForm"
                            :disabled="!hasImageLimit"
                            @submit="handleImageSubmit"
                            @error="showError"
                        />
                    </div>

                    <div
                        v-if="displayGalleryItems.length > 0"
                        ref="galleryScrollRef"
                        class="max-h-[min(72vh,760px)] overflow-y-auto pr-0.5"
                    >
                        <AiImageGalleryStrip
                            :items="displayGalleryItems"
                            :active-id="activeGalleryId"
                            @select="handleGallerySelect"
                        />
                    </div>
                </div>
            </div>
        </template>
    </SubscriberLayout>
</template>