<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from "vue";
import { Clapperboard } from "lucide-vue-next";
import AiImageUploader from "@/components/subscriber/ai/AiImageUploader.vue";
import AiVideoUploader from "@/components/subscriber/ai/AiVideoUploader.vue";
import Button from "@/components/ui/Button.vue";
import Label from "@/components/ui/Label.vue";
import Textarea from "@/components/ui/Textarea.vue";
import { toAiMediaUrl } from "@/composables/useAiMediaUrl";
import { creditsWord } from "@/utils/credits";

const props = defineProps({
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    pricing: { type: Object, default: () => ({}) },
});

const emit = defineEmits(["submit", "error", "task-type-change"]);

function createDefaultForm() {
    return {
        task_type: "generate_video",
        prompt: "",
        image: "",
        images: [],
        source_video: "",
        source_video_duration: null,
        source_video_height: null,
        duration: 5,
        resolution: "480p",
        aspect_ratio: "16:9",
    };
}

const form = reactive(createDefaultForm());
const sourcePrompt = ref("");
const sourceVideoObjectUrl = ref("");

const MIN_DURATION = 3;
const MAX_DURATION = 15;

const taskTypes = [
    { value: "generate_video", label: "Текст" },
    { value: "generate_video_from_image", label: "Изображение" },
    { value: "generate_video_from_scene", label: "Сцена" },
    { value: "edit_video", label: "Редактирование" },
];

const aspectRatios = [
    { value: "16:9", label: "16:9" },
    { value: "9:16", label: "9:16" },
    { value: "1:1", label: "1:1" },
    { value: "4:3", label: "4:3" },
    { value: "3:4", label: "3:4" },
];

const resolutions = [
    { value: "480p", label: "480p" },
    { value: "720p", label: "720p" },
];

const maxDuration = computed(() => (
    form.task_type === "generate_video_from_scene" ? 10 : MAX_DURATION
));

const durationOptions = computed(() => (
    Array.from({ length: maxDuration.value - MIN_DURATION + 1 }, (_, index) => MIN_DURATION + index)
));

function clampDuration(taskType = form.task_type, duration = form.duration) {
    const max = taskType === "generate_video_from_scene" ? 10 : MAX_DURATION;
    const parsed = Number(duration);

    if (!Number.isFinite(parsed) || parsed < MIN_DURATION) {
        return MIN_DURATION;
    }

    return Math.min(parsed, max);
}

const isEditVideo = computed(() => form.task_type === "edit_video");

const hasReferenceContext = computed(() =>
    Boolean(sourcePrompt.value.trim())
    || form.image
    || form.images.length > 0
    || Boolean(form.source_video),
);

const inputPlaceholder = computed(() => {
    if (form.task_type === "generate_video_from_image") {
        return hasReferenceContext.value
            ? "Опишите, что должно происходить с изображением..."
            : "Загрузите изображение и опишите сцену...";
    }

    if (form.task_type === "generate_video_from_scene") {
        return hasReferenceContext.value
            ? "Опишите правки для этой сцены..."
            : "Подробно опишите сцену по референсам...";
    }

    if (form.task_type === "edit_video") {
        return form.source_video
            ? "Опишите, что изменить в этом ролике..."
            : "Загрузите ролик и опишите, что изменить...";
    }

    return hasReferenceContext.value
        ? "Опишите правки для этого видео..."
        : "Опишите, что создать...";
});

function billedDuration(seconds) {
    const parsed = Number(seconds);
    if (!Number.isFinite(parsed) || parsed <= 0) {
        return 5;
    }

    return Math.max(1, Math.ceil(parsed - 1e-9));
}

function billedResolution(height) {
    return Number(height) > 0 && Number(height) <= 480 ? "480p" : "720p";
}

function quoteVideoCost(resolution, duration) {
    const byResolution = props.pricing?.video?.amounts?.[resolution] ?? {};
    const exact = Number(byResolution[String(duration)]);
    if (Number.isFinite(exact) && exact > 0) {
        return exact;
    }

    const perSecond = Number(byResolution["5"] ?? 0) / 5;

    return Math.max(0, Math.round(perSecond * duration));
}

const editDuration = computed(() => billedDuration(form.source_video_duration));
const editResolution = computed(() => billedResolution(form.source_video_height));

const totalCost = computed(() => {
    if (isEditVideo.value) {
        return quoteVideoCost(editResolution.value, editDuration.value);
    }

    return quoteVideoCost(form.resolution, form.duration);
});

function resolvePreviewUrl(value) {
    if (!value) {
        return "";
    }

    if (typeof value === "string" && value.startsWith("data:")) {
        return value;
    }

    return toAiMediaUrl(value, { allowDataUrl: true }) || value;
}

const imagePreviewUrl = computed(() => resolvePreviewUrl(form.image));

const scenePreviewUrls = computed(() =>
    form.images.map((img) => resolvePreviewUrl(img)).filter(Boolean),
);

const sourceVideoPreviewUrl = computed(() => {
    if (form.source_video instanceof File) {
        return sourceVideoObjectUrl.value;
    }

    if (typeof form.source_video === "string" && form.source_video) {
        return toAiMediaUrl(form.source_video, { allowDataUrl: true }) || form.source_video;
    }

    return "";
});

function revokeSourceVideoUrl() {
    if (sourceVideoObjectUrl.value) {
        URL.revokeObjectURL(sourceVideoObjectUrl.value);
        sourceVideoObjectUrl.value = "";
    }
}

function clearSourceVideo() {
    form.source_video = "";
    form.source_video_duration = null;
    form.source_video_height = null;
}

watch(() => form.source_video, (value) => {
    revokeSourceVideoUrl();
    if (value instanceof File) {
        sourceVideoObjectUrl.value = URL.createObjectURL(value);
    }
});

onBeforeUnmount(() => {
    revokeSourceVideoUrl();
});

const canGenerate = computed(() => {
    if (!form.prompt.trim()) {
        return false;
    }

    if (form.task_type === "generate_video_from_image" && !form.image) {
        return false;
    }

    if (form.task_type === "generate_video_from_scene") {
        if (!form.images.length || form.images.length > 7) {
            return false;
        }
    }

    if (form.task_type === "edit_video" && !form.source_video) {
        return false;
    }

    return true;
});

function pluralCredits(n) {
    return creditsWord(n);
}

function insertSourcePrompt() {
    if (!sourcePrompt.value.trim() || props.disabled) {
        return;
    }

    form.prompt = sourcePrompt.value;
    sourcePrompt.value = "";
}

function setTaskType(value) {
    form.task_type = value;
    form.duration = clampDuration(value, form.duration);

    if (value === "generate_video") {
        form.image = "";
        form.images = [];
        clearSourceVideo();
    } else if (value === "generate_video_from_image") {
        form.images = [];
        clearSourceVideo();
    } else if (value === "generate_video_from_scene") {
        form.image = "";
        clearSourceVideo();
    } else if (value === "edit_video") {
        form.image = "";
        form.images = [];
        emit("task-type-change", value);
    }
}

function submit() {
    if (!canGenerate.value) {
        return;
    }

    if (form.task_type === "edit_video") {
        emit("submit", {
            task_type: form.task_type,
            prompt: form.prompt.trim(),
            video: form.source_video,
            duration: editDuration.value,
            resolution: editResolution.value,
        });
        return;
    }

    emit("submit", {
        task_type: form.task_type,
        prompt: form.prompt.trim(),
        image: form.image,
        images: [...form.images],
        duration: form.duration,
        resolution: form.resolution,
        aspect_ratio: form.aspect_ratio,
    });
}

function onImageAdded(results) {
    const first = Array.isArray(results) ? results[0] : results;
    if (first && form.task_type === "generate_video_from_image") {
        form.image = first;
    }
}

function onSceneFilesAdded(results) {
    if (!Array.isArray(results)) {
        return;
    }

    for (const res of results) {
        if (form.images.length < 7) {
            form.images.push(res);
        }
    }
}

/**
 * Add reference image(s) from canvas drop-zone.
 * Auto-switches task type: 1 file → image-to-video, multiple → scene.
 */
function addImages(results) {
    if (!Array.isArray(results) || results.length === 0) {
        return;
    }

    if (results.length > 1) {
        const existing = form.task_type === "generate_video_from_scene"
            ? [...form.images]
            : [];
        setTaskType("generate_video_from_scene");
        form.images = [];
        for (const res of [...existing, ...results]) {
            if (form.images.length < 7) {
                form.images.push(res);
            }
        }
        return;
    }

    // Single file
    if (form.task_type === "generate_video_from_scene") {
        if (form.images.length < 7) {
            form.images.push(results[0]);
        }
        return;
    }

    setTaskType("generate_video_from_image");
    form.image = results[0];
    form.images = [];
}

function updateImage(idx, imgBase64) {
    if (form.task_type === "generate_video_from_image") {
        form.image = imgBase64 || "";
        return;
    }

    if (imgBase64) {
        form.images[idx] = imgBase64;
    } else {
        form.images.splice(idx, 1);
    }
}

function onSourceVideoAdded(result) {
    if (!result?.file) {
        return;
    }

    form.source_video = result.file;
    form.source_video_duration = result.duration;
    form.source_video_height = result.height;
}

function handleImageError(type) {
    const messages = {
        "size-exceeded": "Размер файла не должен превышать 10 МБ",
        "format-not-allowed": "Формат не поддерживается. Загрузите PNG, JPG, JPEG, WEBP или статичный GIF.",
        "animated-gif": "Анимированные GIF не поддерживаются.",
        "duration-exceeded": "Ролик слишком длинный. Нужен файл не длиннее 8 секунд.",
    };
    emit("error", messages[type] || "Ошибка загрузки изображения");
}

function handleVideoError(type) {
    const messages = {
        "size-exceeded": "Файл слишком большой. Загрузите ролик до 25 МБ.",
        "format-not-allowed": "Этот ролик не подходит. Загрузите обычный MP4-файл.",
        "duration-exceeded": "Ролик слишком длинный. Нужен файл не длиннее 8 секунд.",
    };
    emit("error", messages[type] || "Не удалось загрузить ролик");
}

function resetForm() {
    Object.assign(form, createDefaultForm());
    sourcePrompt.value = "";
}

function getSnapshot() {
    return {
        prompt: form.prompt,
        sourcePrompt: sourcePrompt.value,
        task_type: form.task_type,
        duration: form.duration,
        resolution: form.resolution,
        aspect_ratio: form.aspect_ratio,
        image: form.image,
        images: [...form.images],
        source_video: form.source_video,
        source_video_duration: form.source_video_duration,
        source_video_height: form.source_video_height,
    };
}

function applySnapshot(snapshot = {}) {
    if (typeof snapshot.prompt === "string") {
        form.prompt = snapshot.prompt;
    }

    if (typeof snapshot.sourcePrompt === "string") {
        sourcePrompt.value = snapshot.sourcePrompt;
    } else if ("prompt" in snapshot && !("sourcePrompt" in snapshot) && typeof snapshot.prompt === "string") {
        sourcePrompt.value = snapshot.prompt;
        form.prompt = "";
    }

    if (snapshot.task_type) {
        form.task_type = snapshot.task_type;
    }

    if (snapshot.duration) {
        form.duration = clampDuration(form.task_type, snapshot.duration);
    }

    if (snapshot.resolution) {
        form.resolution = snapshot.resolution;
    }

    if (snapshot.aspect_ratio) {
        form.aspect_ratio = snapshot.aspect_ratio;
    }

    if (typeof snapshot.image === "string") {
        form.image = snapshot.image;
    }

    if (Array.isArray(snapshot.images)) {
        form.images = [...snapshot.images];
    }

    if ("source_video" in snapshot) {
        form.source_video = snapshot.source_video || "";
    }

    if (snapshot.source_video_duration != null) {
        form.source_video_duration = snapshot.source_video_duration;
    }

    if (snapshot.source_video_height != null) {
        form.source_video_height = snapshot.source_video_height;
    }

    if (typeof form.source_video === "string" && form.source_video && form.source_video_duration == null) {
        probeSourceVideoUrl(form.source_video);
    }
}

function probeSourceVideoUrl(url) {
    const resolved = toAiMediaUrl(url, { allowDataUrl: true }) || url;
    const video = document.createElement("video");
    video.preload = "metadata";
    video.muted = true;
    video.src = resolved;
    video.onloadedmetadata = () => {
        form.source_video_duration = Number(video.duration);
        form.source_video_height = Number(video.videoHeight);
    };
}

function applySeed(seed = {}) {
    applySnapshot(seed);
}

defineExpose({ applySeed, applySnapshot, getSnapshot, resetForm, addImages });
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <button
                v-for="type in taskTypes"
                :key="type.value"
                type="button"
                class="rounded-md border px-2 py-1 text-[11px] transition-colors"
                :class="form.task_type === type.value ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-muted/50'"
                :disabled="disabled"
                @click="setTaskType(type.value)"
            >
                {{ type.label }}
            </button>
        </div>

        <div
            v-if="form.task_type === 'generate_video_from_image'"
            class="flex flex-wrap items-center gap-2"
        >
            <div
                v-if="imagePreviewUrl"
                class="relative h-12 w-12 overflow-hidden rounded-lg border bg-muted"
            >
                <img :src="imagePreviewUrl" alt="" class="h-full w-full object-cover" />
                <button
                    v-if="!disabled"
                    type="button"
                    class="absolute inset-0 bg-black/0 text-[10px] text-white opacity-0 transition-opacity hover:bg-black/40 hover:opacity-100"
                    @click="form.image = ''"
                >
                    ×
                </button>
            </div>
            <AiImageUploader
                v-if="!form.image"
                v-model="form.image"
                :compact="true"
                :disabled="disabled"
                @files-added="onImageAdded"
                @error="handleImageError"
            />
        </div>

        <div
            v-else-if="form.task_type === 'generate_video_from_scene'"
            class="flex flex-wrap items-center gap-2"
        >
            <div
                v-for="(preview, idx) in scenePreviewUrls"
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
                @files-added="onSceneFilesAdded"
                @error="handleImageError"
            />
        </div>

        <div
            v-else-if="form.task_type === 'edit_video'"
            class="flex flex-wrap items-center gap-2"
        >
            <div
                v-if="sourceVideoPreviewUrl"
                class="relative h-12 w-12 overflow-hidden rounded-lg border bg-muted"
            >
                <video
                    :src="sourceVideoPreviewUrl"
                    muted
                    playsinline
                    preload="metadata"
                    class="h-full w-full object-cover"
                />
                <button
                    v-if="!disabled"
                    type="button"
                    class="absolute inset-0 bg-black/0 text-[10px] text-white opacity-0 transition-opacity hover:bg-black/40 hover:opacity-100"
                    @click="clearSourceVideo"
                >
                    ×
                </button>
            </div>
            <AiVideoUploader
                v-if="!form.source_video"
                :disabled="disabled"
                @files-added="onSourceVideoAdded"
                @error="handleVideoError"
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
                    v-model="form.prompt"
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
                    <Clapperboard class="h-3.5 w-3.5" />
                    <span class="hidden sm:inline">Создать</span>
                    <span class="text-[10px] opacity-80">· {{ totalCost }}</span>
                </Button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted-foreground">
            <template v-if="isEditVideo">
                <span v-if="form.source_video_duration" class="text-[11px]">
                    Длительность ролика: {{ Math.round(form.source_video_duration) }} с
                </span>
                <span class="text-[11px]">{{ totalCost }} {{ pluralCredits(totalCost) }}</span>
            </template>
            <template v-else>
            <div class="flex items-center gap-1.5">
                <Label class="text-[11px]">Длительность</Label>
                <div class="flex gap-1">
                    <button
                        v-for="seconds in durationOptions"
                        :key="seconds"
                        type="button"
                        class="rounded-md border px-1.5 py-0.5 transition-colors"
                        :class="form.duration === seconds ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-muted/50'"
                        :disabled="disabled"
                        @click="form.duration = seconds"
                    >
                        {{ seconds }}с
                    </button>
                </div>
            </div>

            <div class="flex items-center gap-1.5">
                <Label class="text-[11px]">Качество</Label>
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

            <div v-if="form.task_type === 'generate_video'" class="flex items-center gap-1.5">
                <Label class="text-[11px]">Формат</Label>
                <div class="flex gap-1">
                    <button
                        v-for="ar in aspectRatios"
                        :key="ar.value"
                        type="button"
                        class="rounded-md border px-1.5 py-0.5 transition-colors"
                        :class="form.aspect_ratio === ar.value ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-muted/50'"
                        :disabled="disabled"
                        @click="form.aspect_ratio = ar.value"
                    >
                        {{ ar.label }}
                    </button>
                </div>
            </div>

            <span class="text-[11px]">{{ totalCost }} {{ pluralCredits(totalCost) }}</span>
            </template>
        </div>
    </div>
</template>