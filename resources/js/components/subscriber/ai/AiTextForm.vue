<script setup>
import { computed, reactive, ref, watch } from "vue";
import { Check, FileText, Info, Layers, Sparkles, Store, Wand2 } from "lucide-vue-next";
import AiImageUploader from "@/components/subscriber/ai/AiImageUploader.vue";
import Alert from "@/components/ui/Alert.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import Select from "@/components/ui/Select.vue";
import Tabs from "@/components/ui/Tabs.vue";
import TabsContent from "@/components/ui/TabsContent.vue";
import TabsList from "@/components/ui/TabsList.vue";
import TabsTrigger from "@/components/ui/TabsTrigger.vue";
import Textarea from "@/components/ui/Textarea.vue";

const props = defineProps({
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(["submit", "error"]);

const activeTab = ref("generate");
const selectedRewriteAction = ref("adapt");

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

const rewriteActions = computed(() => {
    const marketplaceLabel = form.marketplace === "ozon" ? "Ozon" : "Wildberries";
    const adaptTaskType = form.marketplace === "ozon" ? "rewrite_ozon" : "rewrite_wb";

    const actions = [
        {
            id: "improve",
            taskType: "rewrite_text",
            title: "Улучшить продажность",
            icon: Sparkles,
            summary: "Универсальная переработка без привязки к правилам площадки.",
            useWhen: "Черновик слабый, текст с сайта поставщика или описание «для себя» — нужно сделать убедительнее.",
            result: "Обычный текст: абзацы и списки возможны, формат не подгоняется под карточку.",
        },
        {
            id: "adapt",
            taskType: adaptTaskType,
            title: `Описание для ${marketplaceLabel}`,
            icon: Store,
            summary: form.marketplace === "ozon"
                ? "Текст под поле «Описание» в карточке Ozon: информативно, с акцентом на выгоды."
                : "Текст под поле «Описание» на WB: сплошной текст без абзацев, списков и эмодзи.",
            useWhen: form.marketplace === "ozon"
                ? "Готовите карточку на Ozon и нужен финальный текст для основного описания."
                : "Готовите карточку на WB — площадка не принимает форматирование в описании.",
            result: form.marketplace === "ozon"
                ? "Готовый текст для вставки в описание товара Ozon."
                : "Сплошной текст, готовый к вставке в поле описания WB.",
        },
    ];

    if (form.marketplace === "ozon") {
        actions.push({
            id: "rich",
            taskType: "rich_description",
            title: "Rich-контент Ozon",
            icon: Layers,
            summary: "HTML для расширенного блока Rich content — отдельно от обычного описания.",
            useWhen: "Нужна оформленная витрина: заголовки, структура, акценты — в специальном поле Rich-контент.",
            result: "HTML-код для раздела Rich-контент в личном кабинете Ozon (не в основное описание).",
        });
    }

    return actions;
});

const selectedRewriteOption = computed(() =>
    rewriteActions.value.find((action) => action.id === selectedRewriteAction.value)
    ?? rewriteActions.value[0]
    ?? null,
);

watch(
    () => form.marketplace,
    (marketplace) => {
        if (marketplace === "wb" && selectedRewriteAction.value === "rich") {
            selectedRewriteAction.value = "adapt";
        }
    },
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

function submitSelectedRewrite() {
    const option = selectedRewriteOption.value;
    if (!option) {
        return;
    }

    if (option.taskType === "rich_description") {
        submitRichDescription();
        return;
    }

    submitRewrite(option.taskType);
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
    <Tabs v-model="activeTab" default-value="generate">
        <TabsList class="mb-6 w-full max-w-md">
            <TabsTrigger value="generate" class="flex-1 gap-2">
                <Sparkles class="h-4 w-4" />
                Генерация
            </TabsTrigger>
            <TabsTrigger value="rewrite" class="flex-1 gap-2">
                <FileText class="h-4 w-4" />
                Переработка
            </TabsTrigger>
        </TabsList>

        <TabsContent value="generate" class="space-y-4">
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
                    <Label class="mb-2 block">
                        Фото товара
                        <span class="font-normal text-muted-foreground">(необязательно)</span>
                    </Label>
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
        </TabsContent>

        <TabsContent value="rewrite" class="space-y-5">
            <Alert class="border-blue-200/80 bg-blue-50/80 text-blue-950 dark:border-blue-900/50 dark:bg-blue-950/20 dark:text-blue-100">
                <div class="flex gap-3">
                    <Info class="mt-0.5 h-4 w-4 shrink-0" />
                    <div class="space-y-1">
                        <p class="font-medium">Как пользоваться</p>
                        <p class="text-sm leading-relaxed opacity-90">
                            Вставьте исходный текст, выберите задачу и запустите переработку.
                            «Улучшить продажность» — универсальный вариант.
                            «Описание для площадки» — текст сразу под правила карточки.
                            Rich-контент — только для Ozon, это отдельное HTML-поле, не основное описание.
                        </p>
                    </div>
                </div>
            </Alert>

            <div class="space-y-2">
                <Label>Исходный текст</Label>
                <Textarea
                    v-model="form.description"
                    :disabled="disabled"
                    :rows="6"
                    placeholder="Вставьте описание товара, которое нужно улучшить или адаптировать"
                />
            </div>

            <div class="max-w-xs space-y-2">
                <Label>Карточка для какой площадки?</Label>
                <Select v-model="form.marketplace" :disabled="disabled">
                    <option v-for="item in marketplaceItems" :key="item.value" :value="item.value">
                        {{ item.label }}
                    </option>
                </Select>
                <p class="text-xs text-muted-foreground">
                    Влияет на режим «Описание для площадки» и доступность Rich-контента (только Ozon).
                </p>
            </div>

            <div class="space-y-3">
                <Label>Что сделать с текстом?</Label>
                <div class="grid gap-3 lg:grid-cols-2">
                    <button
                        v-for="action in rewriteActions"
                        :key="action.id"
                        type="button"
                        class="rounded-xl border p-4 text-left transition-colors"
                        :class="selectedRewriteAction === action.id
                            ? 'border-primary bg-primary/5 ring-1 ring-primary/25'
                            : 'hover:border-primary/30 hover:bg-muted/30'"
                        :disabled="disabled"
                        @click="selectedRewriteAction = action.id"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3">
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                                    :class="selectedRewriteAction === action.id
                                        ? 'bg-primary/15 text-primary'
                                        : 'bg-muted text-muted-foreground'"
                                >
                                    <component :is="action.icon" class="h-4 w-4" />
                                </div>
                                <div>
                                    <p class="font-semibold">{{ action.title }}</p>
                                    <p class="mt-1 text-sm text-muted-foreground">{{ action.summary }}</p>
                                </div>
                            </div>
                            <Check
                                v-if="selectedRewriteAction === action.id"
                                class="h-4 w-4 shrink-0 text-primary"
                            />
                        </div>

                        <div class="mt-3 space-y-2 border-t pt-3 text-xs leading-relaxed text-muted-foreground">
                            <p>
                                <span class="font-medium text-foreground">Когда выбрать:</span>
                                {{ action.useWhen }}
                            </p>
                            <p>
                                <span class="font-medium text-foreground">На выходе:</span>
                                {{ action.result }}
                            </p>
                        </div>
                    </button>
                </div>
            </div>

            <Button
                class="w-full sm:w-auto"
                :disabled="disabled || loading || !canRewrite || !selectedRewriteOption"
                @click="submitSelectedRewrite"
            >
                <FileText class="mr-2 h-4 w-4" />
                {{ selectedRewriteOption ? `Переработать: ${selectedRewriteOption.title}` : "Переработать" }}
            </Button>
        </TabsContent>
    </Tabs>
</template>