<script setup>
import { onMounted, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import Checkbox from "@/components/ui/Checkbox.vue";
import Switch from "@/components/ui/Switch.vue";

const props = defineProps({
    clientId: { type: [Number, String], required: true },
    settings: { type: Object, default: null },
    aiLimit: { type: Number, default: 0 },
    updateUrl: { type: String, required: true },
});

const emit = defineEmits(["rating-type-change"]);

const open = ref(false);
const aiStatus = ref(Number(props.settings?.status ?? 0));
const aiRatings = ref([...(props.settings?.ratings ?? [])].map(String));
const reviewType = ref(Array.isArray(props.settings?.review_type) ? [...props.settings.review_type] : []);
const saving = ref(false);
const ready = ref(false);

onMounted(() => {
    ready.value = true;
});

watch(
    () => [aiStatus.value, aiRatings.value, reviewType.value],
    () => {
        if (!ready.value || saving.value) return;
        saving.value = true;
        router.post(
            props.updateUrl,
            {
                status: aiStatus.value,
                ratings: aiRatings.value.map(Number),
                review_type: reviewType.value,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    saving.value = false;
                    emit("rating-type-change", reviewType.value);
                },
            },
        );
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
        <template v-if="aiStatus">
            <p>У вас настроены автоответы при помощи ИИ.</p>
            <p class="mt-1 text-muted-foreground">Осталось запросов к ИИ для отзывов: {{ aiLimit }}</p>
        </template>
        <p v-else>Отвечать на отзывы автоматически при помощи ИИ:</p>

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