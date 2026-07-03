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
    const response = await fetch(image);
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `ai-image-${index + 1}.png`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
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
        <div
            class="grid gap-4"
            :class="normalizedImages.length === 1 ? 'grid-cols-1' : 'sm:grid-cols-2'"
        >
            <div
                v-for="(image, index) in normalizedImages"
                :key="index"
                class="group relative overflow-hidden rounded-xl border bg-muted/30"
            >
                <img
                    :src="image"
                    alt="ИИ изображение"
                    class="min-h-[400px] w-full cursor-pointer object-contain sm:min-h-[480px]"
                    @click="openPreview(image)"
                />
                <div class="absolute bottom-2 right-2 opacity-0 transition-opacity group-hover:opacity-100">
                    <Button size="sm" variant="secondary" @click="downloadImage(image, index)">
                        <Download class="h-4 w-4" />
                    </Button>
                </div>
            </div>
        </div>
    </div>

    <Dialog v-model:open="previewOpen">
        <div class="flex max-h-[80vh] items-center justify-center p-2">
            <img :src="previewImage" alt="Просмотр" class="max-h-[75vh] max-w-full object-contain" />
        </div>
    </Dialog>
</template>