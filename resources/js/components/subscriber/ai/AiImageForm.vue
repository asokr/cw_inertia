<script setup>
import { computed, reactive, ref } from "vue";
import { ImageIcon, Lightbulb, Plus, X } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import Label from "@/components/ui/Label.vue";
import Textarea from "@/components/ui/Textarea.vue";

const props = defineProps({
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(["submit", "error"]);

const showTips = ref(false);
const refFileInput = ref(null);

const form = reactive({
    image_prompt: "",
    image_variants: 1,
    images: [],
    aspectRatio: "3:4",
    resolution: "default",
});

const variants = [1, 2, 3, 4];

const aspectRatios = [
    { value: "1:1", label: "1:1", desc: "Квадрат" },
    { value: "3:4", label: "3:4", desc: "Портрет" },
    { value: "4:3", label: "4:3", desc: "Пейзаж" },
    { value: "9:16", label: "9:16", desc: "Stories" },
    { value: "16:9", label: "16:9", desc: "Широкий" },
];

const resolutions = [
    { value: "default", label: "512px", cost: 1 },
    { value: "1K", label: "1K", cost: 2 },
    { value: "2K", label: "2K", cost: 3 },
    { value: "4K", label: "4K", cost: 3 },
];

const canGenerate = computed(() => Boolean(form.image_prompt.trim()) && Number(form.image_variants) >= 1);

const resolutionMultiplier = computed(() => {
    const found = resolutions.find((r) => r.value === form.resolution);
    return found ? found.cost : 1;
});

const totalCost = computed(() => Number(form.image_variants) * resolutionMultiplier.value);

function pluralCredits(n) {
    if (n === 1) return "лимит";
    if (n >= 2 && n <= 4) return "лимита";
    return "лимитов";
}

function submit() {
    const payload = {
        task_type: "generate_image",
        image_prompt: form.image_prompt.trim(),
        image_variants: Number(form.image_variants),
        aspectRatio: form.aspectRatio,
        resolution: form.resolution,
    };
    if (form.images.length > 0) {
        payload.images = [...form.images];
    }
    emit("submit", payload);
}

const MAX_FILE_SIZE = 10 * 1024 * 1024;
const ALLOWED_TYPES = new Set(["image/png", "image/jpeg", "image/webp", "image/gif"]);
const ALLOWED_EXTENSIONS = new Set(["png", "jpg", "jpeg", "webp", "gif"]);

function isAllowedFile(file) {
    if (!file) return false;
    if (file.type && ALLOWED_TYPES.has(file.type)) return true;
    const ext = file.name?.split(".").pop()?.toLowerCase() || "";
    return ALLOWED_EXTENSIONS.has(ext);
}

async function readFileAsDataUrl(file) {
    return new Promise((resolve) => {
        const reader = new FileReader();
        reader.onload = () => resolve(typeof reader.result === "string" ? reader.result : "");
        reader.readAsDataURL(file);
    });
}

async function handleFilesChange(event) {
    const files = Array.from(event.target?.files || []);
    for (const file of files) {
        if (form.images.length >= 7) break;
        if (!isAllowedFile(file)) {
            emit("error", "Формат не поддерживается. Загрузите PNG, JPG, JPEG, WEBP или GIF.");
            continue;
        }
        if (file.size > MAX_FILE_SIZE) {
            emit("error", "Размер файла не должен превышать 10 МБ");
            continue;
        }
        const dataUrl = await readFileAsDataUrl(file);
        if (dataUrl) form.images.push(dataUrl);
    }
    if (refFileInput.value) refFileInput.value.value = "";
}

function removeImage(idx) {
    form.images.splice(idx, 1);
}
</script>

<template>
    <div class="space-y-5">
        <div class="flex items-start gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-700">
                <ImageIcon class="h-5 w-5" />
            </div>
            <div>
                <h3 class="font-semibold">Генерация изображений</h3>
                <p class="text-sm text-muted-foreground">Опишите, что должно быть на изображении — ИИ создаст варианты</p>
            </div>
        </div>

        <div class="space-y-4">
            <div>
                <Label class="mb-2 block">
                    Изображения <span class="font-normal text-muted-foreground">— до 7 шт. (необязательно)</span>
                </Label>
                <input
                    ref="refFileInput"
                    type="file"
                    accept=".png,.jpg,.jpeg,.webp,.gif"
                    multiple
                    class="hidden"
                    :disabled="disabled"
                    @change="handleFilesChange"
                />
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                    <div
                        v-for="(img, idx) in form.images"
                        :key="idx"
                        class="relative overflow-hidden rounded-xl border bg-muted/30"
                    >
                        <img :src="img" alt="Референс" class="h-28 w-full object-contain" />
                        <button
                            v-if="!disabled"
                            type="button"
                            class="absolute right-1 top-1 rounded-md bg-background/90 p-1 text-muted-foreground hover:text-destructive"
                            @click="removeImage(idx)"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <button
                        v-if="form.images.length < 7"
                        type="button"
                        class="flex h-28 flex-col items-center justify-center gap-1 rounded-xl border border-dashed text-muted-foreground hover:border-primary hover:text-primary"
                        :disabled="disabled"
                        @click="refFileInput?.click()"
                    >
                        <Plus class="h-5 w-5" />
                        <span class="text-xs">{{ form.images.length ? "Добавить ещё" : "Загрузить" }}</span>
                    </button>
                </div>
            </div>

            <div class="space-y-2">
                <Label>Описание изображения</Label>
                <Textarea
                    v-model="form.image_prompt"
                    :disabled="disabled"
                    :rows="3"
                    placeholder="Например: Сделай продающий баннер товара в чистом минималистичном стиле"
                />
            </div>

            <div>
                <Label class="mb-2 block">Соотношение сторон</Label>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="ar in aspectRatios"
                        :key="ar.value"
                        type="button"
                        class="rounded-lg border px-3 py-2 text-left text-sm transition-colors"
                        :class="form.aspectRatio === ar.value ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-muted/50'"
                        :disabled="disabled"
                        @click="form.aspectRatio = ar.value"
                    >
                        <div class="font-medium">{{ ar.label }}</div>
                        <div class="text-xs text-muted-foreground">{{ ar.desc }}</div>
                    </button>
                </div>
            </div>

            <div>
                <Label class="mb-2 block">Разрешение</Label>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="res in resolutions"
                        :key="res.value"
                        type="button"
                        class="rounded-lg border px-3 py-2 text-sm transition-colors"
                        :class="form.resolution === res.value ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-muted/50'"
                        :disabled="disabled"
                        @click="form.resolution = res.value"
                    >
                        <span class="font-medium">{{ res.label }}</span>
                        <span class="ml-2 text-xs text-muted-foreground">{{ res.cost }} {{ pluralCredits(res.cost) }}</span>
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <div class="space-y-2">
                    <Label>Количество вариантов</Label>
                    <select
                        v-model="form.image_variants"
                        :disabled="disabled"
                        class="flex h-9 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option v-for="v in variants" :key="v" :value="v">{{ v }}</option>
                    </select>
                </div>
                <p v-if="form.image_variants > 0" class="text-sm text-muted-foreground">
                    Стоимость: <strong>{{ totalCost }}</strong> {{ pluralCredits(totalCost) }}
                </p>
            </div>

            <Button class="w-full sm:w-auto" :disabled="disabled || loading || !canGenerate" @click="submit">
                Сгенерировать изображения
            </Button>

            <div class="rounded-xl border">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-4 py-3 text-sm font-medium"
                    @click="showTips = !showTips"
                >
                    <Lightbulb class="h-4 w-4 text-amber-500" />
                    Советы для лучшего результата
                </button>
                <div v-show="showTips" class="space-y-3 border-t px-4 py-3 text-sm text-muted-foreground">
                    <p><strong>Товары:</strong> укажите ракурс, фон, освещение. Добавьте «фотореалистично, высокая детализация».</p>
                    <p><strong>Люди:</strong> опишите позу, выражение, одежду, окружение.</p>
                    <p><strong>Редактирование:</strong> одна задача за раз — «убери фон», «добавь тень».</p>
                </div>
            </div>
        </div>
    </div>
</template>