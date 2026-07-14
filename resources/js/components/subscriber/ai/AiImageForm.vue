<script setup>
import { computed, reactive, ref } from "vue";
import { Sparkles } from "lucide-vue-next";
import AiImageUploader from "@/components/subscriber/ai/AiImageUploader.vue";
import Button from "@/components/ui/Button.vue";
import Label from "@/components/ui/Label.vue";
import Textarea from "@/components/ui/Textarea.vue";
import { toAiMediaUrl } from "@/composables/useAiMediaUrl";

const props = defineProps({
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(["submit", "error"]);

const form = reactive({
    image_prompt: "",
    images: [],
    aspectRatio: null,
    resolution: "default",
});

const sourcePrompt = ref("");

const aspectRatios = [
    { value: "1:1", label: "1:1" },
    { value: "3:4", label: "3:4" },
    { value: "4:3", label: "4:3" },
    { value: "9:16", label: "9:16" },
    { value: "16:9", label: "16:9" },
];

const resolutions = [
    { value: "default", label: "512px", cost: 1 },
    { value: "1K", label: "1K", cost: 2 },
    { value: "2K", label: "2K", cost: 3 },
    { value: "4K", label: "4K", cost: 3 },
];

const hasReferenceContext = computed(() =>
    Boolean(sourcePrompt.value.trim()) || form.images.length > 0,
);

const inputPlaceholder = computed(() =>
    hasReferenceContext.value
        ? "Опишите правки для этого кадра..."
        : "Опишите, что создать...",
);

const canGenerate = computed(() => Boolean(form.image_prompt.trim()));

const resolutionMultiplier = computed(() => {
    const found = resolutions.find((r) => r.value === form.resolution);
    return found ? found.cost : 1;
});

const totalCost = computed(() => resolutionMultiplier.value);

const referencePreviews = computed(() =>
    form.images
        .map((img) => toAiMediaUrl(img, { allowDataUrl: true }))
        .filter(Boolean),
);

function pluralCredits(n) {
    if (n === 1) return "лимит";
    if (n >= 2 && n <= 4) return "лимита";
    return "лимитов";
}

function insertSourcePrompt() {
    if (!sourcePrompt.value.trim() || props.disabled) {
        return;
    }

    form.image_prompt = sourcePrompt.value;
    sourcePrompt.value = "";
}

function toggleAspectRatio(value) {
    if (props.disabled) {
        return;
    }

    form.aspectRatio = form.aspectRatio === value ? null : value;
}

function submit() {
    const payload = {
        task_type: "generate_image",
        image_prompt: form.image_prompt.trim(),
        resolution: form.resolution,
    };

    if (form.aspectRatio) {
        payload.aspectRatio = form.aspectRatio;
    }

    if (form.images.length > 0) {
        payload.images = [...form.images];
    }

    emit("submit", payload);
}

function handleImageError(type) {
    const messages = {
        "size-exceeded": "Размер файла не должен превышать 10 МБ",
        "format-not-allowed": "Формат не поддерживается. Загрузите PNG, JPG, JPEG, WEBP или статичный GIF.",
        "animated-gif": "Анимированные GIF не поддерживаются.",
    };
    emit("error", messages[type] || "Ошибка загрузки изображения");
}

function onFilesAdded(results) {
    for (const res of results) {
        if (form.images.length < 7) {
            form.images.push(res);
        }
    }
}

function updateImage(idx, imgBase64) {
    if (imgBase64) {
        form.images[idx] = imgBase64;
    } else {
        form.images.splice(idx, 1);
    }
}

function resetForm() {
    form.image_prompt = "";
    form.images = [];
    form.aspectRatio = null;
    form.resolution = "default";
    sourcePrompt.value = "";
}

function applySeed(seed = {}) {
    applySnapshot(seed);
}

function getSnapshot() {
    return {
        prompt: form.image_prompt,
        sourcePrompt: sourcePrompt.value,
        aspectRatio: form.aspectRatio,
        resolution: form.resolution,
        images: [...form.images],
    };
}

function applySnapshot(snapshot = {}) {
    if (typeof snapshot.prompt === "string") {
        form.image_prompt = snapshot.prompt;
    }

    if (typeof snapshot.sourcePrompt === "string") {
        sourcePrompt.value = snapshot.sourcePrompt;
    } else if ("prompt" in snapshot && !("sourcePrompt" in snapshot) && typeof snapshot.prompt === "string") {
        sourcePrompt.value = snapshot.prompt;
        form.image_prompt = "";
    }

    if ("aspectRatio" in snapshot) {
        form.aspectRatio = snapshot.aspectRatio || null;
    }

    if (snapshot.resolution) {
        const normalized = String(snapshot.resolution).toLowerCase();
        const map = { default: "default", "1k": "1K", "2k": "2K", "4k": "4K" };
        form.resolution = map[normalized] || snapshot.resolution;
    }

    if (Array.isArray(snapshot.images)) {
        form.images = [...snapshot.images];
    }
}

defineExpose({ applySeed, applySnapshot, getSnapshot, resetForm });
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <div
                v-for="(preview, idx) in referencePreviews"
                :key="idx"
                class="relative h-12 w-12 overflow-hidden rounded-lg border bg-muted"
            >
                <img :src="preview" alt="" class="h-full w-full object-cover" />
                <button
                    v-if="!disabled"
                    type="button"
                    class="absolute inset-0 bg-black/0 text-[10px] text-white opacity-0 transition-opacity hover:bg-black/40 hover:opacity-100"
                    @click="updateImage(idx, '')"
                >
                    ×
                </button>
            </div>
            <AiImageUploader
                v-if="form.images.length < 7"
                model-value=""
                :multiple="true"
                :compact="true"
                :disabled="disabled"
                @files-added="onFilesAdded"
                @error="handleImageError"
            />
        </div>

        <div class="overflow-hidden rounded-2xl border border-border/80 bg-muted/25 shadow-sm">
            <button
                v-if="sourcePrompt.trim()"
                type="button"
                class="block w-full border-b border-border/50 px-4 py-2.5 text-left transition-colors hover:bg-muted/40"
                :disabled="disabled"
                :title="disabled ? undefined : 'Вставить в поле ввода'"
                @click="insertSourcePrompt"
            >
                <span class="line-clamp-2 text-sm leading-snug text-muted-foreground">
                    {{ sourcePrompt }}
                </span>
            </button>

            <div class="relative">
                <Textarea
                    v-model="form.image_prompt"
                    :disabled="disabled"
                    :rows="sourcePrompt.trim() ? 2 : 3"
                    class="min-h-[72px] resize-none rounded-none border-0 bg-transparent px-4 py-3 pr-24 text-sm shadow-none focus-visible:ring-0"
                    :placeholder="inputPlaceholder"
                />
                <Button
                    size="sm"
                    class="absolute bottom-2.5 right-2.5 h-8 gap-1.5 px-3"
                    :disabled="disabled || loading || !canGenerate"
                    @click="submit"
                >
                    <Sparkles class="h-3.5 w-3.5" />
                    <span class="hidden sm:inline">Создать</span>
                    <span class="text-[10px] opacity-80">· {{ totalCost }}</span>
                </Button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted-foreground">
            <div class="flex items-center gap-1.5">
                <Label class="text-[11px]">Формат</Label>
                <div class="flex gap-1">
                    <button
                        v-for="ar in aspectRatios"
                        :key="ar.value"
                        type="button"
                        class="rounded-md border px-1.5 py-0.5 transition-colors"
                        :class="form.aspectRatio === ar.value ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-muted/50'"
                        :disabled="disabled"
                        @click="toggleAspectRatio(ar.value)"
                    >
                        {{ ar.label }}
                    </button>
                </div>
            </div>

            <div class="flex items-center gap-1.5">
                <Label class="text-[11px]">Размер</Label>
                <div class="flex gap-1">
                    <button
                        v-for="res in resolutions"
                        :key="res.value"
                        type="button"
                        class="rounded-md border px-1.5 py-0.5 transition-colors"
                        :class="form.resolution === res.value ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-muted/50'"
                        :disabled="disabled"
                        @click="form.resolution = res.value"
                    >
                        {{ res.label }}
                    </button>
                </div>
            </div>

            <span class="text-[11px]">{{ totalCost }} {{ pluralCredits(totalCost) }}</span>
        </div>
    </div>
</template>