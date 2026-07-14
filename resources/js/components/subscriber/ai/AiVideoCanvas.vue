<script setup>
import { computed, ref } from "vue";
import { AlertCircle, Clapperboard, Download, Loader2, ShieldAlert } from "lucide-vue-next";
import { normalizeVideoItem, toAiMediaUrl } from "@/composables/useAiMediaUrl";

const props = defineProps({
    task: { type: Object, default: null },
    loading: { type: Boolean, default: false },
});

const expiredMessage = "Срок ожидания истёк. Запустите генерацию повторно.";
const downloading = ref(false);

const posterUrl = computed(() => {
    if (!props.task) {
        return "";
    }

    const videoUrl = normalizeVideoItem(props.task.video)?.url;
    if (videoUrl && props.task.status === "done") {
        return "";
    }

    if (props.task.image) {
        return toAiMediaUrl(props.task.image, { allowDataUrl: true }) || "";
    }

    if (Array.isArray(props.task.images) && props.task.images.length > 0) {
        return toAiMediaUrl(props.task.images[0], { allowDataUrl: true }) || "";
    }

    return "";
});

const resolvedVideoUrl = computed(() => {
    if (props.task?.status !== "done") {
        return "";
    }

    return normalizeVideoItem(props.task?.video)?.url || "";
});

const showLoadingOverlay = computed(() =>
    props.loading || props.task?.status === "pending",
);

async function downloadVideo() {
    const url = resolvedVideoUrl.value;
    const requestId = props.task?.request_id;
    if (!url || !requestId || downloading.value) {
        return;
    }

    downloading.value = true;
    try {
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error("Download failed");
        }

        const blob = await response.blob();
        const blobUrl = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = blobUrl;
        link.download = `video-${requestId}.mp4`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
    } finally {
        downloading.value = false;
    }
}
</script>

<template>
    <div class="relative flex h-full min-h-0 w-full items-center justify-center overflow-hidden rounded-2xl border bg-muted/30">
        <template v-if="task">
            <template v-if="task.status === 'done' && resolvedVideoUrl">
                <video
                    :key="resolvedVideoUrl"
                    :src="resolvedVideoUrl"
                    :poster="posterUrl || undefined"
                    controls
                    loop
                    playsinline
                    preload="metadata"
                    class="max-h-full max-w-full rounded-xl bg-black object-contain"
                />
                <button
                    v-if="!showLoadingOverlay"
                    type="button"
                    class="absolute right-3 top-3 flex h-9 w-9 items-center justify-center rounded-full border bg-background/90 text-foreground shadow-sm backdrop-blur-sm transition-colors hover:bg-background"
                    title="Скачать"
                    :disabled="downloading"
                    @click="downloadVideo"
                >
                    <Loader2 v-if="downloading" class="h-4 w-4 animate-spin" />
                    <Download v-else class="h-4 w-4" />
                </button>
            </template>

            <template v-else-if="task.status === 'done' && !resolvedVideoUrl">
                <div class="flex max-w-sm flex-col items-center gap-3 px-6 text-center">
                    <AlertCircle class="h-10 w-10 text-destructive" />
                    <p class="text-sm font-semibold">Ошибка генерации</p>
                    <p class="text-sm text-muted-foreground">Получен некорректный URL сгенерированного видео</p>
                </div>
            </template>

            <template v-else-if="task.status === 'filtered_by_moderation'">
                <div class="flex max-w-sm flex-col items-center gap-3 px-6 text-center">
                    <ShieldAlert class="h-10 w-10 text-amber-600" />
                    <p class="text-sm font-semibold">Не прошло модерацию</p>
                    <p class="text-sm text-muted-foreground">
                        {{ task.error || "Видео не прошло модерацию. Измените запрос и попробуйте снова." }}
                    </p>
                </div>
            </template>

            <template v-else-if="task.error || task.status === 'expired' || task.status === 'error'">
                <div class="flex max-w-sm flex-col items-center gap-3 px-6 text-center">
                    <AlertCircle class="h-10 w-10 text-destructive" />
                    <p class="text-sm font-semibold">Ошибка генерации</p>
                    <p class="text-sm text-muted-foreground">{{ task.error || expiredMessage }}</p>
                </div>
            </template>

            <template v-else>
                <img
                    v-if="posterUrl"
                    :src="posterUrl"
                    alt=""
                    class="max-h-full max-w-full object-contain"
                />
                <div
                    v-else
                    class="flex h-14 w-14 items-center justify-center rounded-2xl bg-muted"
                >
                    <Clapperboard class="h-7 w-7 opacity-40 text-muted-foreground" />
                </div>
            </template>

            <div
                v-if="showLoadingOverlay"
                class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-background/55 backdrop-blur-[1px]"
            >
                <Loader2 class="h-9 w-9 animate-spin text-primary" />
                <p class="text-sm text-muted-foreground">Генерация видео...</p>
            </div>
        </template>

        <div v-else-if="loading" class="flex flex-col items-center gap-3 text-muted-foreground">
            <Loader2 class="h-9 w-9 animate-spin text-primary" />
            <p class="text-sm">Генерация...</p>
        </div>

        <div v-else class="flex max-w-sm flex-col items-center gap-2 px-6 text-center text-muted-foreground">
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-muted">
                <Clapperboard class="h-7 w-7 opacity-40" />
            </div>
            <p class="text-sm">Опишите видео ниже или выберите сессию в истории сверху</p>
        </div>
    </div>
</template>