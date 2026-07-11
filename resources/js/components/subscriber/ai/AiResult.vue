<script setup>
import { computed, ref } from "vue";
import { Copy, Download, ImageIcon, Loader2 } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import { toAiMediaUrl } from "@/composables/useAiMediaUrl";

const props = defineProps({
    mode: { type: String, default: "text" },
    loading: { type: Boolean, default: false },
    textResult: { type: String, default: "" },
    richDescriptionResult: { type: String, default: "" },
    images: { type: Array, default: () => [] },
});

const previewOpen = ref(false);
const previewImage = ref("");
const downloadingIndex = ref(null);

const normalizedImages = computed(() =>
    props.images
        .map((img) => toAiMediaUrl(img, { allowDataUrl: true }))
        .filter(Boolean),
);

async function copyText(text) {
    if (!text) return;
    await navigator.clipboard.writeText(text);
}

function openPreview(image) {
    previewImage.value = image;
    previewOpen.value = true;
}

async function downloadImage(image, index) {
    if (downloadingIndex.value !== null) return;

    downloadingIndex.value = index;
    try {
        const response = await fetch(image);
        if (!response.ok) throw new Error("Download failed");
        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        link.download = `ai-image-${index + 1}.png`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } finally {
        downloadingIndex.value = null;
    }
}
</script>

<template>
    <div v-if="loading" class="rounded-2xl border bg-card p-8 text-center">
        <Loader2 class="mx-auto h-6 w-6 animate-spin text-primary" />
        <p class="mt-3 text-sm text-muted-foreground">ИИ обрабатывает запрос...</p>
    </div>

    <template v-else-if="mode === 'text'">
        <div v-if="textResult" class="rounded-2xl border bg-card p-5">
            <div class="mb-3 flex items-center justify-between gap-3">
                <span class="text-sm font-semibold">Результат</span>
                <Button variant="ghost" size="sm" @click="copyText(textResult)">
                    <Copy class="mr-1.5 h-4 w-4" />
                    Копировать
                </Button>
            </div>
            <div class="whitespace-pre-wrap text-sm leading-relaxed">{{ textResult }}</div>
        </div>

        <div v-if="richDescriptionResult" class="mt-4 rounded-2xl border bg-card p-5">
            <div class="mb-3 flex items-center justify-between gap-3">
                <span class="text-sm font-semibold">Rich-контент</span>
                <Button variant="ghost" size="sm" @click="copyText(richDescriptionResult)">
                    <Copy class="mr-1.5 h-4 w-4" />
                    Копировать
                </Button>
            </div>
            <div class="whitespace-pre-wrap font-mono text-xs leading-relaxed">{{ richDescriptionResult }}</div>
        </div>
    </template>

    <div v-else-if="mode === 'image' && normalizedImages.length" class="rounded-2xl border bg-card p-5">
        <div class="mb-4 flex items-center gap-2 text-sm font-semibold">
            <ImageIcon class="h-4 w-4 text-primary" />
            Сгенерированные изображения
        </div>
        <div class="flex flex-wrap gap-4">
            <div
                v-for="(image, index) in normalizedImages"
                :key="index"
                class="group relative w-full max-w-[400px] overflow-hidden rounded-xl border bg-muted/30"
            >
                <button
                    type="button"
                    class="block w-full cursor-zoom-in text-left"
                    @click="openPreview(image)"
                >
                    <img
                        :src="image"
                        alt="ИИ изображение"
                        class="h-auto w-full object-contain transition-transform duration-200 group-hover:scale-[1.02]"
                    />
                </button>
                <div
                    class="pointer-events-none absolute inset-0 bg-black/0 transition-colors duration-200 group-hover:bg-black/10"
                />
                <button
                    type="button"
                    class="absolute right-2.5 top-2.5 flex h-9 w-9 items-center justify-center rounded-full border border-border/60 bg-background/95 text-foreground opacity-0 shadow-sm backdrop-blur-sm transition-all duration-200 hover:bg-background group-hover:opacity-100"
                    :aria-label="`Скачать изображение ${index + 1}`"
                    :disabled="downloadingIndex === index"
                    @click.stop="downloadImage(image, index)"
                >
                    <Loader2 v-if="downloadingIndex === index" class="h-4 w-4 animate-spin" />
                    <Download v-else class="h-4 w-4" />
                </button>
            </div>
        </div>
    </div>

    <Dialog
        v-model:open="previewOpen"
        title="Просмотр изображения"
        class="max-w-[min(95vw,1400px)]"
    >
        <div class="flex items-center justify-center">
            <img
                :src="previewImage"
                alt="Просмотр"
                class="max-h-[min(85vh,1200px)] max-w-full rounded-lg object-contain"
            />
        </div>
        <template #footer>
            <Button
                variant="outline"
                size="sm"
                :disabled="downloadingIndex !== null"
                @click="downloadImage(previewImage, normalizedImages.indexOf(previewImage))"
            >
                <Download class="mr-1.5 h-4 w-4" />
                Скачать
            </Button>
        </template>
    </Dialog>
</template>