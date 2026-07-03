<script setup>
import { computed, reactive } from "vue";
import { FileText, Sparkles, Wand2 } from "lucide-vue-next";
import AiImageUploader from "@/components/subscriber/ai/AiImageUploader.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import Select from "@/components/ui/Select.vue";
import Textarea from "@/components/ui/Textarea.vue";

const props = defineProps({
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(["submit", "error"]);

const form = reactive({
    title: "",
    features: "",
    prompt: "",
    image: "",
    marketplace: "wb",
    description: "",
});

const marketplaceItems = [
    { value: "wb", label: "Wildberries" },
    { value: "ozon", label: "Ozon" },
];

const canGenerateDescription = computed(() =>
    Boolean(form.title.trim() || form.features.trim() || form.image || form.prompt.trim()),
);

const canRewrite = computed(() => Boolean(form.description.trim()));

const marketplaceRewriteLabel = computed(() =>
    form.marketplace === "ozon" ? "Адаптировать под Ozon" : "Адаптировать под WB",
);

function submitGenerate() {
    emit("submit", {
        task_type: "generate_description",
        marketplace: form.marketplace,
        title: form.title.trim() || undefined,
        features: form.features.trim() || undefined,
        description: form.prompt.trim() || undefined,
        image: form.image || undefined,
    });
}

function submitRewrite(taskType) {
    emit("submit", {
        task_type: taskType,
        marketplace: form.marketplace,
        description: form.description.trim(),
        title: form.title.trim() || undefined,
        features: form.features.trim() || undefined,
        image: form.image || undefined,
    });
}

function submitRewriteForMarketplace() {
    const taskType = form.marketplace === "ozon" ? "rewrite_ozon" : "rewrite_wb";
    submitRewrite(taskType);
}

function submitRichDescription() {
    emit("submit", {
        task_type: "rich_description",
        marketplace: "ozon",
        description: form.description.trim(),
        title: form.title.trim() || undefined,
        features: form.features.trim() || undefined,
        image: form.image || undefined,
    });
}

function handleImageError(type) {
    const messages = {
        "size-exceeded": "Размер файла не должен превышать 10 МБ",
        "format-not-allowed": "Формат не поддерживается. Загрузите PNG, JPG, JPEG, WEBP или статичный GIF.",
        "animated-gif": "Анимированные GIF не поддерживаются. Загрузите статичное изображение.",
    };
    emit("error", messages[type] || "Ошибка загрузки изображения");
}
</script>

<template>
    <div class="space-y-8">
        <section class="space-y-4">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-700">
                    <Sparkles class="h-5 w-5" />
                </div>
                <div>
                    <h3 class="font-semibold">Генерация описания</h3>
                    <p class="text-sm text-muted-foreground">Укажите название, характеристики или загрузите фото товара</p>
                </div>
            </div>

            <div class="space-y-4 sm:pl-[52px]">
                <div class="grid gap-4 sm:grid-cols-[1fr_180px]">
                    <div class="space-y-2">
                        <Label>Название товара</Label>
                        <Input v-model="form.title" :disabled="disabled" placeholder="Например: Куртка мужская зимняя" />
                    </div>
                    <div class="space-y-2">
                        <Label>Маркетплейс</Label>
                        <Select v-model="form.marketplace" :disabled="disabled">
                            <option v-for="item in marketplaceItems" :key="item.value" :value="item.value">
                                {{ item.label }}
                            </option>
                        </Select>
                    </div>
                </div>

                <div class="space-y-2">
                    <Label>Характеристики товара</Label>
                    <Textarea
                        v-model="form.features"
                        :disabled="disabled"
                        :rows="3"
                        placeholder="Материал, размеры, цвет, состав и другие важные характеристики"
                    />
                </div>

                <div class="rounded-xl border bg-muted/20 p-4">
                    <Label class="mb-2 block">Фото товара <span class="font-normal text-muted-foreground">(необязательно)</span></Label>
                    <AiImageUploader v-model="form.image" :disabled="disabled" @error="handleImageError" />
                </div>

                <div class="space-y-2">
                    <Label>Указание для ИИ</Label>
                    <Textarea
                        v-model="form.prompt"
                        :disabled="disabled"
                        :rows="2"
                        placeholder="Например: Сгенерируй 3 варианта названия и продающее описание"
                    />
                </div>

                <Button :disabled="disabled || loading || !canGenerateDescription" @click="submitGenerate">
                    <Wand2 class="mr-2 h-4 w-4" />
                    Сгенерировать описание
                </Button>
            </div>
        </section>

        <div class="flex items-center gap-4 text-xs font-medium uppercase tracking-wide text-muted-foreground">
            <div class="h-px flex-1 bg-border" />
            или
            <div class="h-px flex-1 bg-border" />
        </div>

        <section class="space-y-4">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-purple-100 text-purple-700">
                    <FileText class="h-5 w-5" />
                </div>
                <div>
                    <h3 class="font-semibold">Переработка текста</h3>
                    <p class="text-sm text-muted-foreground">Вставьте готовый текст — улучшим или адаптируем под маркетплейс</p>
                </div>
            </div>

            <div class="space-y-4 sm:pl-[52px]">
                <div class="space-y-2">
                    <Label>Текст для переписывания</Label>
                    <Textarea
                        v-model="form.description"
                        :disabled="disabled"
                        :rows="4"
                        placeholder="Вставьте текущее описание товара, которое нужно улучшить"
                    />
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button variant="secondary" :disabled="disabled || loading || !canRewrite" @click="submitRewrite('rewrite_text')">
                        Улучшить
                    </Button>
                    <Button variant="outline" :disabled="disabled || loading || !canRewrite" @click="submitRewriteForMarketplace">
                        {{ marketplaceRewriteLabel }}
                    </Button>
                    <Button
                        v-if="form.marketplace === 'ozon'"
                        variant="outline"
                        :disabled="disabled || loading || !canRewrite"
                        @click="submitRichDescription"
                    >
                        Сгенерировать Rich-контент
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>