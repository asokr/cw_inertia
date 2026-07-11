<script setup>
import { computed, onMounted, ref } from "vue";
import { Head } from "@inertiajs/vue3";
import AiLimitsBadge from "@/components/subscriber/ai/AiLimitsBadge.vue";
import AiVideoForm from "@/components/subscriber/ai/AiVideoForm.vue";
import AiVideoGenerationsList from "@/components/subscriber/ai/AiVideoGenerationsList.vue";
import AiVideoHistorySidebar from "@/components/subscriber/ai/AiVideoHistorySidebar.vue";
import AiVideoResult from "@/components/subscriber/ai/AiVideoResult.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useFlashToast } from "@/composables/useFlashToast";
import { useMarketplaceAi } from "@/composables/useMarketplaceAi";

const props = defineProps({
    limits: {
        type: Object,
        default: () => ({ text: 0, image: 0, video: 0 }),
    },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "ИИ Инструменты", href: "/panel/ai/text" },
    { label: "Видео" },
];

const activeVideoTask = ref(null);
const deletingGenerationId = ref(null);
const { showError, showSuccess } = useFlashToast();

const {
    loading,
    limitsLoading,
    videoLimit,
    videoHistory,
    savedGenerations,
    activeGenerationId,
    generationsLoading,
    openGeneration,
    createGeneration,
    deleteGeneration,
    restoreActiveGeneration,
    hasVideoLimit,
    refreshLimits,
    runVideoTask,
    runSceneVideoTask,
} = useMarketplaceAi(props.limits, {
    limitsMode: "video",
    onVideoError: (message) => showError(message),
    onVideoDone: (task) => {
        if (task?.video?.url) {
            showSuccess("Видео готово");
        }
    },
});

const hasResult = computed(() => videoHistory.value.length > 0);

const selectedVideoTask = computed(() => {
    if (!activeVideoTask.value || !videoHistory.value.length) {
        return null;
    }

    return videoHistory.value.find((task) => task.request_id === activeVideoTask.value) ?? null;
});

async function handleVideoSubmit(payload) {
    const duration = Number(payload?.duration || 5);
    const resMultiplier = payload?.resolution === "720p" ? 2 : 1;
    const totalCost = duration * resMultiplier;
    const currentLimit = Number(videoLimit.value ?? 0);

    if (!hasVideoLimit.value || currentLimit < totalCost) {
        showError(`Недостаточно лимитов AI_VIDEO_QUERY (нужно ${totalCost}, доступно ${currentLimit})`);
        return;
    }

    let result;
    if (payload.task_type === "generate_video_from_scene") {
        result = await runSceneVideoTask(payload);
    } else {
        result = await runVideoTask(payload);
    }

    if (!result.ok) {
        showError(result.message);
        return;
    }

    if (result.requestId) {
        activeVideoTask.value = result.requestId;
        showSuccess("Генерация видео запущена");
    }
}

async function handleOpenGeneration(generationId) {
    const result = await openGeneration(generationId);
    if (!result.ok) {
        showError(result.message);
        return;
    }

    activeVideoTask.value = result.preferredTaskId ?? result.tasks?.[0]?.request_id ?? null;
}

async function handleCreateGeneration() {
    const result = await createGeneration();
    if (!result.ok) {
        showError(result.message);
        return;
    }

    activeVideoTask.value = null;
    showSuccess("Создана новая генерация");
}

async function handleDeleteGeneration(generationId) {
    if (!window.confirm("Удалить генерацию и все связанные видео?")) {
        return;
    }

    deletingGenerationId.value = generationId;
    const result = await deleteGeneration(generationId);
    deletingGenerationId.value = null;

    if (!result.ok) {
        showError(result.message);
        return;
    }

    showSuccess("Генерация удалена");
}

onMounted(async () => {
    await refreshLimits();
    const restored = await restoreActiveGeneration();
    activeVideoTask.value = restored?.preferredTaskId ?? null;
});
</script>

<template>
    <Head title="ИИ — Видео" />

    <SubscriberLayout title="ИИ — Видео" :breadcrumbs="breadcrumbs">
        <ToolPageHeader title="Генерация видео" description="Text-to-video, анимация изображений и сцены по референсам">
            <template #actions>
                <AiLimitsBadge mode="video" :loading="limitsLoading" :video-limit="videoLimit" />
            </template>
        </ToolPageHeader>

        <div v-if="limitsLoading" class="mt-6 space-y-4">
            <Skeleton class="h-64 w-full rounded-2xl" />
        </div>

        <template v-else>
            <div class="mt-5 space-y-6">
                <div class="grid gap-6 lg:grid-cols-[1fr_250px]">
                    <div class="space-y-6 rounded-[20px] border bg-card p-6 shadow-sm">
                        <AiVideoForm
                            :loading="loading"
                            :disabled="!hasVideoLimit"
                            :selected-task="selectedVideoTask"
                            @submit="handleVideoSubmit"
                            @error="showError"
                        />
                        <div v-if="hasResult || loading">
                            <AiVideoResult :tasks="videoHistory" :active-task-id="activeVideoTask" />
                        </div>
                    </div>
                    <AiVideoHistorySidebar
                        v-if="videoHistory.length > 0"
                        :tasks="videoHistory"
                        :active-task-id="activeVideoTask"
                        @select="activeVideoTask = $event"
                    />
                </div>

                <AiVideoGenerationsList
                    :items="savedGenerations"
                    :active-id="activeGenerationId"
                    :loading="generationsLoading"
                    :deleting-id="deletingGenerationId"
                    @open="handleOpenGeneration"
                    @create="handleCreateGeneration"
                    @delete="handleDeleteGeneration"
                />
            </div>
        </template>
    </SubscriberLayout>
</template>