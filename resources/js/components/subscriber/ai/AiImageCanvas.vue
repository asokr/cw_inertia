<script setup>
import { computed, ref } from "vue";
import { Download, ImageIcon, Loader2 } from "lucide-vue-next";
import { toAiMediaUrl } from "@/composables/useAiMediaUrl";

const props = defineProps({
    image: { type: String, default: "" },
    loading: { type: Boolean, default: false },
});

const downloading = ref(false);

const displayUrl = computed(() => toAiMediaUrl(props.image, { allowDataUrl: true }) || "");

async function downloadImage() {
    if (!displayUrl.value || downloading.value) {
        return;
    }

    downloading.value = true;
    try {
        const response = await fetch(displayUrl.value);
        if (!response.ok) {
            throw new Error("Download failed");
        }

        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        link.download = "ai-image.png";
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } finally {
        downloading.value = false;
    }
}
</script>

<template>
    <div class="relative flex min-h-[min(72vh,760px)] flex-1 items-center justify-center overflow-hidden rounded-2xl border bg-muted/30 lg:min-h-[calc(100vh-300px)]">
        <template v-if="displayUrl">
            <img
                :src="displayUrl"
                alt="Результат"
                class="max-h-[min(72vh,760px)] w-full object-contain lg:max-h-[calc(100vh-300px)]"
            />
            <button
                v-if="!loading"
                type="button"
                class="absolute right-3 top-3 flex h-9 w-9 items-center justify-center rounded-full border bg-background/90 text-foreground shadow-sm backdrop-blur-sm transition-colors hover:bg-background"
                title="Скачать"
                :disabled="downloading"
                @click="downloadImage"
            >
                <Loader2 v-if="downloading" class="h-4 w-4 animate-spin" />
                <Download v-else class="h-4 w-4" />
            </button>
            <div
                v-if="loading"
                class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-background/55 backdrop-blur-[1px]"
            >
                <Loader2 class="h-9 w-9 animate-spin text-primary" />
                <p class="text-sm text-muted-foreground">Генерация...</p>
            </div>
        </template>

        <div v-else-if="loading" class="flex flex-col items-center gap-3 text-muted-foreground">
            <Loader2 class="h-9 w-9 animate-spin text-primary" />
            <p class="text-sm">Генерация...</p>
        </div>

        <div v-else class="flex max-w-sm flex-col items-center gap-2 px-6 text-center text-muted-foreground">
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-muted">
                <ImageIcon class="h-7 w-7 opacity-40" />
            </div>
            <p class="text-sm">Опишите изображение или выберите превью справа, чтобы доработать его</p>
        </div>
    </div>
</template>