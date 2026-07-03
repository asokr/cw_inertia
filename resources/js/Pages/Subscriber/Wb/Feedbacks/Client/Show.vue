<script setup>
import { computed, ref } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import { ChevronLeft, ChevronRight, RefreshCw } from "lucide-vue-next";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import AiAutoSettings from "@/components/subscriber/wb/feedbacks/AiAutoSettings.vue";
import AnsweredReviewsWidget from "@/components/subscriber/wb/feedbacks/AnsweredReviewsWidget.vue";
import FeedbackCard from "@/components/subscriber/wb/feedbacks/FeedbackCard.vue";
import Alert from "@/components/ui/Alert.vue";
import Button from "@/components/ui/Button.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";

const props = defineProps({
    client: { type: Object, required: true },
    feedbacks: { type: Array, default: () => [] },
    feedbacksError: { type: String, default: null },
    aiSettings: { type: Object, default: null },
    aiLimit: { type: Number, default: 0 },
    answeredReviews: { type: Array, default: () => [] },
    ratingType: { type: [String, Array], default: null },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Управление отзывами", href: "/panel/wb/feedbacks" },
    { label: props.client.name },
];

const page = ref(1);
const perPage = ref(10);
const localRatingType = ref(props.ratingType);

const pageCount = computed(() => Math.max(1, Math.ceil(props.feedbacks.length / perPage.value)));
const pageItems = computed(() => {
    const start = (page.value - 1) * perPage.value;
    return props.feedbacks.slice(start, start + perPage.value);
});

const baseUrl = `/panel/wb/feedbacks/clients/${props.client.id}`;
const sendUrl = `${baseUrl}/feedbacks/send`;
const generateUrl = `${baseUrl}/ai/generate`;
const updateAiUrl = `${baseUrl}/ai`;

function refresh() {
    router.post(`${baseUrl}/feedbacks`, {}, { preserveScroll: true });
}

function showAll() {
    perPage.value = props.feedbacks.length || 10;
    page.value = 1;
}
</script>

<template>
    <Head :title="`Отзывы — ${client.name}`" />

    <SubscriberLayout :title="`Список отзывов — ${client.name}`" :breadcrumbs="breadcrumbs">
        <ToolPageHeader title="Список отзывов" :description="client.name">
            <template #actions>
                <Link :href="`/panel/wb/feedbacks/clients/${client.id}/templates`">
                    <Button variant="outline">Шаблоны отзывов</Button>
                </Link>
                <Button variant="outline" @click="refresh">
                    <RefreshCw class="mr-2 h-4 w-4" />
                    Обновить с WB
                </Button>
            </template>
        </ToolPageHeader>

        <AiAutoSettings
            :client-id="client.id"
            :settings="aiSettings"
            :ai-limit="aiLimit"
            :update-url="updateAiUrl"
            @rating-type-change="localRatingType = $event"
        />

        <Alert v-if="feedbacksError" variant="destructive" class="mb-4">
            {{ feedbacksError }}
        </Alert>

        <div v-if="feedbacks.length" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-muted-foreground">Всего отзывов: {{ feedbacks.length }}</p>
                <div v-if="perPage < feedbacks.length" class="flex items-center gap-2">
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
                v-for="feedback in pageItems"
                :key="feedback.id"
                :feedback="feedback"
                :client-id="client.id"
                :rating-type="localRatingType"
                :send-url="sendUrl"
                :generate-url="generateUrl"
            />
        </div>

        <div v-else-if="!feedbacksError" class="space-y-4">
            <p class="text-sm text-muted-foreground">Неотвеченных отзывов нет.</p>
            <Button @click="refresh">
                <RefreshCw class="mr-2 h-4 w-4" />
                Обновить данные с WB
            </Button>
        </div>

        <div class="mt-8">
            <AnsweredReviewsWidget :items="answeredReviews" :client-id="client.id" />
        </div>
    </SubscriberLayout>
</template>