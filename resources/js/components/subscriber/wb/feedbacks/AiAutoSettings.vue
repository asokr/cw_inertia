<script setup>
import { onMounted, onUnmounted, ref, watch } from "vue";
import Checkbox from "@/components/ui/Checkbox.vue";
import Switch from "@/components/ui/Switch.vue";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    clientId: { type: [Number, String], required: true },
    settings: { type: Object, default: null },
    aiLimit: { type: Number, default: 0 },
    updateUrl: { type: String, required: true },
});

const emit = defineEmits(["rating-type-change"]);

const { showError } = useFlashToast();

const open = ref(false);
const aiStatus = ref(Number(props.settings?.status ?? 0));
const aiRatings = ref([...(props.settings?.ratings ?? [])].map(String));
const reviewType = ref(Array.isArray(props.settings?.review_type) ? [...props.settings.review_type] : []);
const saving = ref(false);
const saved = ref(false);
const ready = ref(false);

let saveTimer = null;
let savedTimer = null;

onMounted(() => {
    ready.value = true;
});

onUnmounted(() => {
    clearTimeout(saveTimer);
    clearTimeout(savedTimer);
});

async function persistSettings() {
    if (!ready.value || saving.value) {
        return;
    }

    saving.value = true;
    saved.value = false;

    try {
        const response = await fetch(props.updateUrl, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.content ?? "",
            },
            body: JSON.stringify({
                status: aiStatus.value,
                ratings: aiRatings.value.map(Number),
                review_type: reviewType.value,
            }),
            credentials: "same-origin",
        });
        const data = await response.json();

        if (!data.success) {
            showError(data.messages?.join(" ") || "Не удалось сохранить настройки");
            return;
        }

        saved.value = true;
        clearTimeout(savedTimer);
        savedTimer = setTimeout(() => {
            saved.value = false;
        }, 2000);
        emit("rating-type-change", reviewType.value);
    } catch {
        showError("Не удалось сохранить настройки");
    } finally {
        saving.value = false;
    }
}

function queueSave() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
        persistSettings();
    }, 400);
}

watch(
    () => [aiStatus.value, aiRatings.value, reviewType.value],
    () => {
        if (!ready.value) {
            return;
        }

        queueSave();
    },
    { deep: true },
);

function toggleRating(value) {
    const key = String(value);
    if (aiRatings.value.includes(key)) {
        aiRatings.value = aiRatings.value.filter((r) => r !== key);
    } else {
        aiRatings.value = [...aiRatings.value, key];
    }
}
</script>

<template>
    <div class="rounded-lg border p-4 text-sm">
        <div class="flex items-start justify-between gap-3">
            <div>
                <template v-if="aiStatus">
                    <p>У вас настроены автоответы при помощи ИИ.</p>
                    <p class="mt-1 text-muted-foreground">Осталось запросов к ИИ для отзывов: {{ aiLimit }}</p>
                </template>
                <p v-else>Отвечать на отзывы автоматически при помощи ИИ:</p>
            </div>
            <p v-if="saving" class="shrink-0 text-xs text-muted-foreground">Сохранение…</p>
            <p v-else-if="saved" class="shrink-0 text-xs text-primary">Сохранено</p>
        </div>

        <button type="button" class="mt-2 text-sm font-medium text-primary hover:underline" @click="open = !open">
            Настройки
        </button>

        <div v-if="open" class="mt-4 space-y-4">
            <div class="flex items-center gap-3">
                <Switch :model-value="Boolean(aiStatus)" @update:model-value="aiStatus = $event ? 1 : 0" />
                <span>{{ aiStatus ? "Включено" : "Выключено" }}</span>
            </div>

            <div v-if="aiStatus" class="space-y-3">
                <p class="text-muted-foreground">На отзывы с какой оценкой будет отвечать ИИ:</p>
                <div class="flex flex-wrap gap-3">
                    <label v-for="n in 5" :key="n" class="flex items-center gap-2">
                        <Checkbox
                            :model-value="aiRatings.includes(String(n))"
                            @update:model-value="toggleRating(n)"
                        />
                        {{ n }}
                    </label>
                </div>
                <label class="flex items-center gap-2">
                    <Checkbox
                        :model-value="reviewType.includes('stih')"
                        @update:model-value="reviewType = $event ? ['stih'] : []"
                    />
                    Отвечать в стихотворной форме
                </label>
                <p class="text-xs text-muted-foreground">
                    При ответах расходуется лимит ИИ на отзывы. ИИ-автоответы имеют приоритет над шаблонами.
                </p>
            </div>
        </div>
    </div>
</template>