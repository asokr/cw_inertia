<script setup>
import { onMounted, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import Checkbox from "@/components/ui/Checkbox.vue";
import Switch from "@/components/ui/Switch.vue";
import Textarea from "@/components/ui/Textarea.vue";

const props = defineProps({
    settings: { type: Object, default: null },
    aiLimit: { type: Number, default: 0 },
    updateUrl: { type: String, required: true },
});

const emit = defineEmits(["signature-change"]);

const open = ref(true);
const aiStatus = ref(Number(props.settings?.status ?? 0));
const aiRatings = ref([...(props.settings?.ratings ?? [])].map(String));
const emptyAnswer = ref(Boolean(props.settings?.empty_answer));
const signature = ref(props.settings?.signature ?? "");
const saving = ref(false);
const ready = ref(false);

onMounted(() => {
    ready.value = true;
    emit("signature-change", signature.value);
});

watch(
    () => [aiStatus.value, aiRatings.value, emptyAnswer.value, signature.value],
    () => {
        if (!ready.value || saving.value) return;
        saving.value = true;
        emit("signature-change", signature.value);
        router.post(
            props.updateUrl,
            {
                status: aiStatus.value,
                ratings: aiRatings.value.map(Number),
                empty_answer: emptyAnswer.value,
                signature: signature.value,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    saving.value = false;
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
            {{ open ? "Закрыть настройки" : "Открыть настройки" }}
        </button>

        <div v-if="open" class="mt-4 space-y-4">
            <div class="flex items-center gap-3">
                <Switch :model-value="Boolean(aiStatus)" @update:model-value="aiStatus = $event ? 1 : 0" />
                <span>{{ aiStatus ? "Включено" : "Выключено" }}</span>
            </div>

            <div v-if="aiStatus" class="space-y-4">
                <div>
                    <p class="text-muted-foreground">На отзывы с какой оценкой будет отвечать ИИ:</p>
                    <div class="mt-2 flex flex-wrap gap-3">
                        <label v-for="n in 5" :key="n" class="flex items-center gap-2">
                            <Checkbox
                                :model-value="aiRatings.includes(String(n))"
                                @update:model-value="toggleRating(n)"
                            />
                            {{ n }}
                        </label>
                    </div>
                </div>

                <label class="flex items-center gap-2">
                    <Checkbox v-model="emptyAnswer" />
                    Отвечать на отзывы без текста
                </label>

                <div class="space-y-2">
                    <p class="text-muted-foreground">Подпись к ответу на отзыв</p>
                    <Textarea v-model="signature" :rows="2" placeholder="С уважением, …" />
                </div>

                <p class="text-xs text-muted-foreground">При ответах расходуется лимит ИИ на отзывы.</p>
            </div>
        </div>
    </div>
</template>