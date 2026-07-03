<script setup>
import { computed, ref } from "vue";
import { AlertCircle, Download, Loader2, ShieldAlert } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import { toAiMediaUrl } from "@/composables/useAiMediaUrl";

const props = defineProps({
    tasks: { type: Array, default: () => [] },
    activeTaskId: { type: String, default: null },
});

const expiredMessage = "Срок ожидания истёк. Запустите генерацию повторно.";
const downloading = ref(false);

const activeTask = computed(() => {
    if (!props.tasks?.length) return null;
    if (props.activeTaskId) {
        const found = props.tasks.find((t) => t.request_id === props.activeTaskId);
        if (found) return found;
    }
    return props.tasks[0];
});

const resolvedVideoUrl = computed(() => toAiMediaUrl(activeTask.value?.video?.url || ""));

async function downloadVideo() {
    const url = resolvedVideoUrl.value;
    const requestId = activeTask.value?.request_id;
    if (!url || !requestId) return;

    downloading.value = true;
    try {
        const response = await fetch(url);
        if (!response.ok) throw new Error("Download failed");
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
    <div v-if="activeTask">
        <div v-if="activeTask.status === 'pending'" class="rounded-2xl border bg-card p-8 text-center">
            <Loader2 class="mx-auto h-6 w-6 animate-spin text-primary" />
            <p class="mt-3 text-sm text-muted-foreground">Видео генерируется, это может занять несколько минут...</p>
        </div>

        <div
            v-else-if="activeTask.status === 'done' && !resolvedVideoUrl"
            class="rounded-2xl border border-destructive/30 bg-destructive/5 p-6 text-center"
        >
            <AlertCircle class="mx-auto h-8 w-8 text-destructive" />
            <p class="mt-3 font-semibold">Ошибка генерации</p>
            <p class="mt-1 text-sm text-muted-foreground">Получен некорректный URL сгенерированного видео</p>
        </div>

        <div v-else-if="activeTask.status === 'done' && resolvedVideoUrl" class="rounded-2xl border bg-card p-5">
            <div class="mb-4 flex items-center justify-between gap-3">
                <span class="text-sm font-semibold">Видео готово</span>
                <Button variant="ghost" size="sm" :disabled="downloading" @click="downloadVideo">
                    <Download class="mr-1.5 h-4 w-4" />
                    Скачать
                </Button>
            </div>
            <video :src="resolvedVideoUrl" controls loop class="w-full rounded-xl bg-black" />
        </div>

        <div
            v-else-if="activeTask.status === 'filtered_by_moderation'"
            class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-center"
        >
            <ShieldAlert class="mx-auto h-8 w-8 text-amber-600" />
            <p class="mt-3 font-semibold">Не прошло модерацию</p>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ activeTask.error || "Видео не прошло модерацию. Измените запрос и попробуйте снова." }}
            </p>
        </div>

        <div
            v-else-if="activeTask.error || activeTask.status === 'expired' || activeTask.status === 'error'"
            class="rounded-2xl border border-destructive/30 bg-destructive/5 p-6 text-center"
        >
            <AlertCircle class="mx-auto h-8 w-8 text-destructive" />
            <p class="mt-3 font-semibold">Ошибка генерации</p>
            <p class="mt-1 text-sm text-muted-foreground">{{ activeTask.error || expiredMessage }}</p>
        </div>
    </div>
</template>