<script setup>
import { computed, onMounted, ref } from "vue";
import { Head } from "@inertiajs/vue3";
import { Image, Type, Video } from "lucide-vue-next";
import AiImageForm from "@/components/subscriber/ai/AiImageForm.vue";
import AiLimitsBadge from "@/components/subscriber/ai/AiLimitsBadge.vue";
import AiResult from "@/components/subscriber/ai/AiResult.vue";
import AiTextForm from "@/components/subscriber/ai/AiTextForm.vue";
import AiVideoForm from "@/components/subscriber/ai/AiVideoForm.vue";
import AiVideoHistorySidebar from "@/components/subscriber/ai/AiVideoHistorySidebar.vue";
import AiVideoResult from "@/components/subscriber/ai/AiVideoResult.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Alert from "@/components/ui/Alert.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useMarketplaceAi } from "@/composables/useMarketplaceAi";

const props = defineProps({
    limits: {
        type: Object,
        default: () => ({ text: 0, image: 0, video: 0 }),
    },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "ИИ Инструменты" },
];

const activeTab = ref("text");
const activeVideoTask = ref(null);
const flashError = ref("");
const flashSuccess = ref("");

function showError(message) {
    flashError.value = message;
    flashSuccess.value = "";
}

function showSuccess(message) {
    flashSuccess.value = message;
    flashError.value = "";
}

const {
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
} = useMarketplaceAi(props.limits, {
    onVideoError: (message) => showError(message),
    onVideoDone: () => showSuccess("Видео готово"),
});

const hasResult = computed(() => {
    if (activeTab.value === "text") {
        return Boolean(textResult.value || richDescriptionResult.value);
    }
    if (activeTab.value === "video") {
        return videoHistory.value.length > 0;
    }
    return imageResults.value.length > 0;
});

async function handleTextSubmit(payload) {
    if (!hasTextLimit.value) {
        showError("Недостаточно лимитов AI_TEXT_QUERY");
        return;
    }

    const result = await runTextTask(payload);
    if (!result.ok) {
        showError(result.message);
    } else {
        flashError.value = "";
    }
}

async function handleImageSubmit(payload) {
    const variants = Number(payload?.image_variants || 1);
    const resolutionCostMap = { default: 1, "1K": 2, "2K": 3, "4K": 3 };
    const resMultiplier = resolutionCostMap[payload?.resolution] || 1;
    const totalCost = variants * resMultiplier;
    const currentLimit = Number(imageLimit.value ?? 0);

    if (!hasImageLimit.value || currentLimit < totalCost) {
        showError("Недостаточно лимитов AI_IMAGE_QUERY");
        return;
    }

    const result = await runImageTask(payload);
    if (!result.ok) {
        showError(result.message);
    } else {
        flashError.value = "";
    }
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

onMounted(async () => {
    await refreshLimits();
});
</script>

<template>
    <Head title="ИИ Инструменты" />

    <SubscriberLayout title="ИИ Инструменты" :breadcrumbs="breadcrumbs">
        <ToolPageHeader title="ИИ Инструменты">
            <template #actions>
                <AiLimitsBadge
                    :loading="limitsLoading"
                    :text-limit="textLimit"
                    :image-limit="imageLimit"
                    :video-limit="videoLimit"
                />
            </template>
        </ToolPageHeader>

        <Alert v-if="flashSuccess" class="mb-4">{{ flashSuccess }}</Alert>
        <Alert v-if="flashError" variant="destructive" class="mb-4">{{ flashError }}</Alert>

        <div v-if="limitsLoading" class="mt-6 space-y-4">
            <Skeleton class="h-10 w-full max-w-lg" />
            <Skeleton class="h-64 w-full rounded-2xl" />
        </div>

        <template v-else>
            <div class="mt-5 flex justify-start">
                <div class="relative flex w-full max-w-xl gap-0.5 rounded-[14px] bg-slate-100 p-1">
                    <button
                        type="button"
                        class="relative z-10 flex min-w-[120px] flex-1 items-center justify-center gap-2 rounded-[11px] px-4 py-2.5 text-sm font-medium transition-colors sm:min-w-[140px] sm:px-6"
                        :class="activeTab === 'text' ? 'text-blue-700 font-semibold' : 'text-slate-500 hover:text-slate-700'"
                        @click="activeTab = 'text'"
                    >
                        <Type class="h-4 w-4" />
                        Текст
                    </button>
                    <button
                        type="button"
                        class="relative z-10 flex min-w-[120px] flex-1 items-center justify-center gap-2 rounded-[11px] px-4 py-2.5 text-sm font-medium transition-colors sm:min-w-[140px] sm:px-6"
                        :class="activeTab === 'image' ? 'text-blue-700 font-semibold' : 'text-slate-500 hover:text-slate-700'"
                        @click="activeTab = 'image'"
                    >
                        <Image class="h-4 w-4" />
                        Изображения
                    </button>
                    <button
                        type="button"
                        class="relative z-10 flex min-w-[120px] flex-1 items-center justify-center gap-2 rounded-[11px] px-4 py-2.5 text-sm font-medium transition-colors sm:min-w-[140px] sm:px-6"
                        :class="activeTab === 'video' ? 'text-blue-700 font-semibold' : 'text-slate-500 hover:text-slate-700'"
                        @click="activeTab = 'video'"
                    >
                        <Video class="h-4 w-4" />
                        Видео
                    </button>
                    <div
                        class="absolute bottom-1 top-1 rounded-[11px] bg-white shadow-sm transition-all duration-300"
                        :class="{
                            'left-1 w-[calc(33.333%-4px)]': activeTab === 'text',
                            'left-[calc(33.333%+2px)] w-[calc(33.333%-4px)]': activeTab === 'image',
                            'left-[calc(66.666%)] w-[calc(33.333%-4px)]': activeTab === 'video',
                        }"
                    />
                </div>
            </div>

            <div
                class="mt-5"
                :class="activeTab === 'video' ? '' : 'rounded-[20px] border bg-card p-6 shadow-sm'"
            >
                <Transition name="ai-tab-fade" mode="out-in">
                    <div v-if="activeTab === 'text'" key="text">
                        <AiTextForm
                            :loading="loading"
                            :disabled="!hasTextLimit"
                            @submit="handleTextSubmit"
                            @error="showError"
                        />
                    </div>

                    <div v-else-if="activeTab === 'image'" key="image">
                        <AiImageForm
                            :loading="loading"
                            :disabled="!hasImageLimit"
                            @submit="handleImageSubmit"
                            @error="showError"
                        />
                    </div>

                    <div v-else key="video" class="grid gap-6 lg:grid-cols-[1fr_250px]">
                        <div class="space-y-6 rounded-[20px] border bg-card p-6 shadow-sm">
                            <AiVideoForm
                                :loading="loading"
                                :disabled="!hasVideoLimit"
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
                </Transition>
            </div>

            <div v-if="(hasResult || loading) && activeTab !== 'video'" class="mt-6">
                <AiResult
                    :mode="activeTab"
                    :loading="loading"
                    :text-result="textResult"
                    :rich-description-result="richDescriptionResult"
                    :images="imageResults"
                />
            </div>
        </template>
    </SubscriberLayout>
</template>

<style scoped>
.ai-tab-fade-enter-active,
.ai-tab-fade-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.ai-tab-fade-enter-from {
    opacity: 0;
    transform: translateY(8px);
}

.ai-tab-fade-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}
</style>