<script setup>
import { computed, ref } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { ChevronLeft, ChevronRight, RefreshCw } from "lucide-vue-next";
import AiAutoSettings from "@/components/subscriber/oz/feedbacks/AiAutoSettings.vue";
import FeedbackCard from "@/components/subscriber/oz/feedbacks/FeedbackCard.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Button from "@/components/ui/Button.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    cabinet: { type: Object, required: true },
    reviews: { type: Array, default: () => [] },
    reviewsError: { type: String, default: null },
    lastId: { type: String, default: null },
    unprocessedCount: { type: Number, default: null },
    aiSettings: { type: Object, default: null },
    aiLimit: { type: Number, default: 0 },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Управление отзывами", href: "/panel/oz/feedbacks" },
    { label: props.cabinet.name },
];

const page = ref(1);
const perPage = ref(10);
const signature = ref(props.aiSettings?.signature ?? "");

const pageCount = computed(() => Math.max(1, Math.ceil(props.reviews.length / perPage.value)));
const pageItems = computed(() => {
    const start = (page.value - 1) * perPage.value;
    return props.reviews.slice(start, start + perPage.value);
});

const baseUrl = `/panel/oz/feedbacks/cabinets/${props.cabinet.id}`;
const sendUrl = `${baseUrl}/feedbacks/send`;
const generateUrl = `${baseUrl}/ai/generate`;
const updateAiUrl = `${baseUrl}/ai`;

function refresh() {
    router.post(`${baseUrl}/feedbacks`, {}, { preserveScroll: true });
}

function showAll() {
    perPage.value = props.reviews.length || 10;
    page.value = 1;
}

const { watchPropToast } = useFlashToast();
watchPropToast(() => props.reviewsError);
</script>

<template>
    <Head :title="`Отзывы — ${cabinet.name}`" />

    <SubscriberLayout :title="`Список отзывов — ${cabinet.name}`" :breadcrumbs="breadcrumbs">
        <ToolPageHeader title="Список отзывов" :description="cabinet.name">
            <template #actions>
                <Button variant="outline" @click="refresh">
                    <RefreshCw class="mr-2 h-4 w-4" />
                    Обновить с Ozon
                </Button>
            </template>
        </ToolPageHeader>

        <AiAutoSettings
            :settings="aiSettings"
            :ai-limit="aiLimit"
            :update-url="updateAiUrl"
            @signature-change="signature = $event"
        />

        <div v-if="reviews.length" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-muted-foreground">Загружено отзывов: {{ reviews.length }}</p>
                <div v-if="perPage < reviews.length" class="flex items-center gap-2">
                    <Button variant="ghost" size="sm" @click="showAll">Показать все</Button>
                    <Button variant="outline" size="icon" :disabled="page <= 1" @click="page--">
                        <ChevronLeft class="h-4 w-4" />
                    </Button>
                    <span class="text-sm">{{ page }} / {{ pageCount }}</span>
                    <Button variant="outline" size="icon" :disabled="page >= pageCount" @click="page++">
                        <ChevronRight class="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <FeedbackCard
                v-for="review in pageItems"
                :key="review.id"
                :review="review"
                :signature="signature"
                :send-url="sendUrl"
                :generate-url="generateUrl"
            />

            <div
                v-if="perPage < reviews.length"
                class="flex items-center justify-center gap-2 py-2"
            >
                <Button variant="outline" size="icon" :disabled="page <= 1" @click="page--">
                    <ChevronLeft class="h-4 w-4" />
                </Button>
                <span class="text-sm text-muted-foreground">Страница {{ page }} из {{ pageCount }}</span>
                <Button variant="outline" size="icon" :disabled="page >= pageCount" @click="page++">
                    <ChevronRight class="h-4 w-4" />
                </Button>
            </div>
        </div>

        <div v-else-if="!reviewsError" class="space-y-4">
            <p class="text-sm text-muted-foreground">Неотвеченных отзывов нет.</p>
            <Button @click="refresh">
                <RefreshCw class="mr-2 h-4 w-4" />
                Обновить данные с Ozon
            </Button>
        </div>

        <footer
            v-if="unprocessedCount !== null || reviews.length >= 49"
            class="mt-6 flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-muted/40 px-4 py-3 text-sm text-muted-foreground"
        >
            <span v-if="unprocessedCount !== null">
                Всего необработанных отзывов: {{ unprocessedCount }}
            </span>
            <span v-if="reviews.length >= 49">Отображаются последние 50 отзывов</span>
        </footer>
    </SubscriberLayout>
</template>