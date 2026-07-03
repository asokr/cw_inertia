<script setup>
import { computed, reactive } from "vue";
import { Clapperboard } from "lucide-vue-next";
import AiImageUploader from "@/components/subscriber/ai/AiImageUploader.vue";
import Button from "@/components/ui/Button.vue";
import Label from "@/components/ui/Label.vue";
import Textarea from "@/components/ui/Textarea.vue";

const props = defineProps({
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(["submit", "error"]);

const form = reactive({
    task_type: "generate_video",
    prompt: "",
    image: "",
    images: [],
    duration: 5,
    resolution: "480p",
    aspect_ratio: "16:9",
});

const resolutions = [
    { value: "480p", label: "480p", multiplier: 1 },
    { value: "720p", label: "720p", multiplier: 2 },
];

const headerTitle = computed(() => {
    if (form.task_type === "generate_video_from_image") return "Генерация видео из изображения";
    if (form.task_type === "generate_video_from_scene") return "Генерация видео-сцены по референсам";
    return "Генерация видео из текста";
});

const headerSubtitle = computed(() => {
    if (form.task_type === "generate_video_from_image") {
        return "Загрузите изображение и опишите сцену — ИИ создаст видео, где загруженное изображение станет первым кадром.";
    }
    if (form.task_type === "generate_video_from_scene") {
        return "Используйте 1–7 референсных изображений для визуального руководства при генерации сцены.";
    }
    return "Опишите сцену — ИИ сгенерирует видеоролик по описанию";
});

const promptLabel = computed(() => {
    if (form.task_type === "generate_video_from_image") return "Что должно происходить с изображением";
    if (form.task_type === "generate_video_from_scene") return "Подробное описание сцены";
    return "Описание видео";
});

const buttonText = computed(() => {
    if (form.task_type === "generate_video") return "Сгенерировать видео";
    if (form.task_type === "generate_video_from_image") return "Создать видео из изображения";
    return "Сгенерировать сцену";
});

const resolutionMultiplier = computed(() => {
    const found = resolutions.find((r) => r.value === form.resolution);
    return found ? found.multiplier : 1;
});

const totalCost = computed(() => form.duration * resolutionMultiplier.value);

function pluralCredits(n) {
    if (n % 10 === 1 && n % 100 !== 11) return "лимит";
    if ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100)) return "лимита";
    return "лимитов";
}

const isValid = computed(() => {
    if (!form.prompt.trim()) return false;
    if (form.task_type === "generate_video_from_image" && !form.image) return false;
    if (form.task_type === "generate_video_from_scene") {
        if (!form.images?.length || form.images.length > 7) return false;
        if (form.duration > 10) return false;
    }
    return true;
});

function handleSubmit() {
    if (!isValid.value) return;
    emit("submit", { ...form });
}

function onFilesAdded(results) {
    for (const res of results) {
        if (form.images.length < 7) form.images.push(res);
    }
}

function updateImage(idx, imgBase64) {
    if (imgBase64) {
        form.images[idx] = imgBase64;
    } else {
        form.images.splice(idx, 1);
    }
}

function handleImageError(type) {
    const messages = {
        "size-exceeded": "Размер файла не должен превышать 10 МБ",
        "format-not-allowed": "Формат не поддерживается. Загрузите PNG, JPG, JPEG, WEBP или статичный GIF.",
        "animated-gif": "Анимированные GIF не поддерживаются.",
    };
    emit("error", messages[type] || "Ошибка загрузки изображения");
}
</script>

<template>
    <div class="space-y-5">
        <div class="flex items-start gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-100 text-sky-700">
                <Clapperboard class="h-5 w-5" />
            </div>
            <div>
                <h3 class="font-semibold">{{ headerTitle }}</h3>
                <p class="text-sm text-muted-foreground">{{ headerSubtitle }}</p>
            </div>
        </div>

        <div class="space-y-4">
            <div>
                <Label class="mb-2 block">Тип генерации</Label>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="type in [
                            { value: 'generate_video', label: 'Текст → Видео' },
                            { value: 'generate_video_from_image', label: 'Изображение → Видео' },
                            { value: 'generate_video_from_scene', label: 'Изображения → Видео' },
                        ]"
                        :key="type.value"
                        type="button"
                        class="rounded-lg border px-3 py-2 text-sm"
                        :class="form.task_type === type.value ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-muted/50'"
                        @click="form.task_type = type.value"
                    >
                        {{ type.label }}
                    </button>
                </div>
            </div>

            <div v-if="form.task_type === 'generate_video_from_image'" class="rounded-xl border bg-muted/20 p-4">
                <Label class="mb-2 block">Исходное изображение</Label>
                <AiImageUploader v-model="form.image" :disabled="disabled" @error="handleImageError" />
            </div>

            <div v-if="form.task_type === 'generate_video_from_scene'" class="space-y-3">
                <Label>Изображения (от 1 до 7)</Label>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                    <div v-for="(img, idx) in form.images" :key="idx">
                        <AiImageUploader
                            :model-value="img"
                            :disabled="disabled"
                            @update:model-value="(v) => updateImage(idx, v)"
                            @error="handleImageError"
                        />
                    </div>
                    <AiImageUploader
                        v-if="form.images.length < 7"
                        model-value=""
                        :multiple="true"
                        :disabled="disabled"
                        @files-added="onFilesAdded"
                        @error="handleImageError"
                    />
                </div>
            </div>

            <div class="space-y-2">
                <Label>{{ promptLabel }}</Label>
                <Textarea v-model="form.prompt" :disabled="disabled" :rows="3" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="space-y-2">
                    <Label>Длительность (сек)</Label>
                    <input
                        v-model.number="form.duration"
                        type="number"
                        min="1"
                        :max="form.task_type === 'generate_video_from_scene' ? 10 : 15"
                        :disabled="disabled"
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                    />
                </div>
                <div class="space-y-2">
                    <Label>Разрешение</Label>
                    <select v-model="form.resolution" :disabled="disabled" class="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option v-for="res in resolutions" :key="res.value" :value="res.value">{{ res.label }}</option>
                    </select>
                </div>
                <div v-if="form.task_type === 'generate_video'" class="space-y-2">
                    <Label>Соотношение сторон</Label>
                    <select v-model="form.aspect_ratio" :disabled="disabled" class="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="16:9">16:9</option>
                        <option value="9:16">9:16</option>
                        <option value="1:1">1:1</option>
                        <option value="4:3">4:3</option>
                        <option value="3:4">3:4</option>
                    </select>
                </div>
            </div>

            <p class="text-sm text-muted-foreground">
                Стоимость: <strong>{{ totalCost }}</strong> {{ pluralCredits(totalCost) }}
            </p>

            <Button :disabled="disabled || loading || !isValid" @click="handleSubmit">
                {{ buttonText }}
            </Button>
        </div>
    </div>
</template>