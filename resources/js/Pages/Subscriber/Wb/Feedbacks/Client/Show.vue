<script setup>
import { computed, ref, watch } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import { ChevronLeft, ChevronRight, RefreshCw } from "lucide-vue-next";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import AiAutoSettings from "@/components/subscriber/wb/feedbacks/AiAutoSettings.vue";
import AnsweredReviewsWidget from "@/components/subscriber/wb/feedbacks/AnsweredReviewsWidget.vue";
import FeedbackCard from "@/components/subscriber/wb/feedbacks/FeedbackCard.vue";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Input from "@/components/ui/Input.vue";
import Select from "@/components/ui/Select.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    client: { type: Object, required: true },
    feedbacks: { type: Array, default: () => [] },
    feedbacksError: { type: String, default: null },
    feedbacksMeta: {
        type: Object,
        default: () => ({
            total: 0,
            page: 1,
            per_page: 10,
            page_count: 1,
            count_from_wb: 0,
            wb_count_unanswered: null,
            pages_fetched: 0,
            truncated: false,
            brand_filter_active: false,
            brands: [],
            skipped_by_brand: 0,
        }),
    },
    filters: {
        type: Object,
        default: () => ({
            nmId: null,
            ratings: [],
            page: 1,
            per_page: 10,
        }),
    },
    aiSettings: { type: Object, default: null },
    aiLimit: { type: Number, default: 0 },
    ratingType: { type: [String, Array], default: null },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Управление отзывами", href: "/panel/wb/feedbacks" },
    { label: props.client.name },
];

const localRatingType = ref(props.ratingType);
const ratingFilter = ref([...(props.filters.ratings || [])].map(Number));
const nmIdFilter = ref(props.filters.nmId ? String(props.filters.nmId) : "");
const perPage = ref(props.filters.per_page ?? 10);
const loadingFilters = ref(false);

const ratingOptions = [5, 4, 3, 2, 1];

const page = computed(() => props.feedbacksMeta.page || 1);
const pageCount = computed(() => props.feedbacksMeta.page_count || 1);
const total = computed(() => props.feedbacksMeta.total || 0);

const hasActiveFilters = computed(
    () => ratingFilter.value.length > 0 || String(nmIdFilter.value || "").trim() !== ""
);

const baseUrl = `/panel/wb/feedbacks/clients/${props.client.id}`;
const sendUrl = `${baseUrl}/feedbacks/send`;
const generateUrl = `${baseUrl}/ai/generate`;
const updateAiUrl = `${baseUrl}/ai`;

function buildQuery(overrides = {}) {
    const query = {
        page: overrides.page ?? page.value,
        per_page: Number(overrides.per_page ?? perPage.value),
    };

    const ratings = overrides.ratings ?? ratingFilter.value;
    if (ratings?.length) {
        query.ratings = ratings;
    }

    const nmRaw =
        overrides.nmId !== undefined ? overrides.nmId : String(nmIdFilter.value || "").trim();
    if (nmRaw !== "" && nmRaw !== null) {
        query.nmId = nmRaw;
    }

    return query;
}

function applyFilters(overrides = {}) {
    loadingFilters.value = true;
    router.get(baseUrl, buildQuery(overrides), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onFinish: () => {
            loadingFilters.value = false;
        },
    });
}

function refresh() {
    router.post(`${baseUrl}/feedbacks`, buildQuery({ page: 1 }), {
        preserveScroll: true,
    });
}

function toggleRating(star) {
    const n = Number(star);
    let next;
    if (ratingFilter.value.includes(n)) {
        next = ratingFilter.value.filter((r) => r !== n);
    } else {
        next = [...ratingFilter.value, n].sort((a, b) => b - a);
    }
    ratingFilter.value = next;
    applyFilters({ ratings: next, page: 1 });
}

function clearFilters() {
    ratingFilter.value = [];
    nmIdFilter.value = "";
    applyFilters({ ratings: [], nmId: "", page: 1 });
}

function goToPage(nextPage) {
    if (nextPage < 1 || nextPage > pageCount.value) return;
    applyFilters({ page: nextPage });
}

let nmDebounce = null;
function onNmIdInput(value) {
    nmIdFilter.value = value;
    clearTimeout(nmDebounce);
    nmDebounce = setTimeout(() => {
        applyFilters({ nmId: String(value || "").trim(), page: 1 });
    }, 450);
}

function onPerPageChange(value) {
    perPage.value = value;
    applyFilters({ per_page: Number(value), page: 1 });
}

watch(
    () => props.filters,
    (f) => {
        ratingFilter.value = [...(f.ratings || [])].map(Number);
        nmIdFilter.value = f.nmId ? String(f.nmId) : "";
        perPage.value = f.per_page ?? 10;
    },
    { deep: true }
);

const { watchPropToast } = useFlashToast();
watchPropToast(() => props.feedbacksError);
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

        <!-- Неотвеченные отзывы -->
        <Card class="mt-6 overflow-hidden border-amber-200/80 dark:border-amber-900/50">
            <div
                class="border-b border-amber-200/60 bg-amber-50/80 px-4 py-3 dark:border-amber-900/40 dark:bg-amber-950/30"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-semibold">Неотвеченные отзывы</h2>
                            <Badge variant="warning">Требуют ответа</Badge>
                            <Badge v-if="loadingFilters" variant="outline">Загрузка…</Badge>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Данные запрашиваются из API Wildberries. Оценка фильтруется на сервере
                            после ответа WB; nmID передаётся в API WB как точный артикул.
                        </p>
                    </div>
                    <div class="text-right text-sm text-muted-foreground">
                        <div>
                            Найдено:
                            <span class="font-medium text-foreground">{{ total }}</span>
                        </div>
                        <div v-if="feedbacksMeta.count_from_wb || feedbacksMeta.wb_count_unanswered" class="text-xs">
                            скачано с WB: {{ feedbacksMeta.count_from_wb ?? 0 }}
                            <template v-if="feedbacksMeta.wb_count_unanswered != null">
                                · count-unanswered: {{ feedbacksMeta.wb_count_unanswered }}
                            </template>
                            <template v-if="feedbacksMeta.pages_fetched">
                                · стр.: {{ feedbacksMeta.pages_fetched }}
                            </template>
                        </div>
                    </div>
                </div>

                <div
                    v-if="feedbacksMeta.truncated"
                    class="mt-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive"
                >
                    Загружена не вся выборка WB (лимит страниц или сбой API). Часть отзывов может
                    отсутствовать — нажмите «Обновить с WB» позже.
                </div>

                <!-- Brand filter notice -->
                <div
                    v-if="feedbacksMeta.brand_filter_active"
                    class="mt-3 rounded-md border border-amber-300/70 bg-amber-100/70 px-3 py-2 text-xs text-amber-950 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-100"
                >
                    <p class="font-medium">Фильтр по брендам кабинета</p>
                    <p class="mt-0.5 opacity-90">
                        Показываются только отзывы брендов, указанных при добавлении/редактировании
                        кабинета:
                        <span class="font-semibold">{{ feedbacksMeta.brands.join(", ") }}</span>
                        <template v-if="feedbacksMeta.skipped_by_brand">
                            · отсеяно по бренду из скачанных:
                            {{ feedbacksMeta.skipped_by_brand }}
                        </template>
                    </p>
                </div>
                <div
                    v-else
                    class="mt-3 rounded-md border border-dashed border-muted-foreground/30 bg-background/60 px-3 py-2 text-xs text-muted-foreground"
                >
                    Бренды в кабинете не заданы — показываются отзывы по всем брендам API-ключа.
                    Указать бренды можно в
                    <Link href="/panel/wb/feedbacks" class="underline hover:text-foreground">
                        списке кабинетов
                    </Link>
                    (редактирование).
                </div>

                <div class="mt-3 flex flex-wrap items-end gap-3">
                    <div class="space-y-1">
                        <p class="text-xs font-medium text-muted-foreground">Оценка</p>
                        <div class="flex flex-wrap gap-1">
                            <button
                                v-for="star in ratingOptions"
                                :key="star"
                                type="button"
                                class="rounded-md border px-2.5 py-1 text-xs transition-colors"
                                :class="
                                    ratingFilter.includes(star)
                                        ? 'border-amber-500 bg-amber-100 text-amber-900 dark:bg-amber-900/50 dark:text-amber-100'
                                        : 'bg-background hover:bg-muted'
                                "
                                @click="toggleRating(star)"
                            >
                                {{ star }}★
                            </button>
                        </div>
                    </div>

                    <div class="w-44 space-y-1">
                        <label class="text-xs font-medium text-muted-foreground" for="nm-filter">
                            nmID (точный артикул WB)
                        </label>
                        <Input
                            id="nm-filter"
                            :model-value="nmIdFilter"
                            type="text"
                            inputmode="numeric"
                            placeholder="Напр. 123456789"
                            @update:model-value="onNmIdInput"
                        />
                    </div>

                    <div class="w-32 space-y-1">
                        <label class="text-xs font-medium text-muted-foreground" for="per-page">
                            На странице
                        </label>
                        <Select
                            id="per-page"
                            :model-value="perPage"
                            @update:model-value="onPerPageChange"
                        >
                            <option :value="10">10</option>
                            <option :value="20">20</option>
                            <option :value="50">50</option>
                            <option :value="0">Все</option>
                        </Select>
                    </div>

                    <Button
                        v-if="hasActiveFilters"
                        variant="ghost"
                        size="sm"
                        @click="clearFilters"
                    >
                        Сбросить
                    </Button>
                </div>
            </div>

            <div class="space-y-4 p-4">
                <div v-if="feedbacks.length" class="space-y-4">
                    <div
                        v-if="pageCount > 1 || Number(perPage) !== 0"
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <p class="text-sm text-muted-foreground">
                            Страница {{ page }} из {{ pageCount }}
                        </p>
                        <div v-if="pageCount > 1" class="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="icon"
                                :disabled="page <= 1 || loadingFilters"
                                @click="goToPage(page - 1)"
                            >
                                <ChevronLeft class="h-4 w-4" />
                            </Button>
                            <span class="text-sm">{{ page }} / {{ pageCount }}</span>
                            <Button
                                variant="outline"
                                size="icon"
                                :disabled="page >= pageCount || loadingFilters"
                                @click="goToPage(page + 1)"
                            >
                                <ChevronRight class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>

                    <FeedbackCard
                        v-for="feedback in feedbacks"
                        :key="feedback.id"
                        :feedback="feedback"
                        :client-id="client.id"
                        :rating-type="localRatingType"
                        :send-url="sendUrl"
                        :generate-url="generateUrl"
                    />

                    <div
                        v-if="pageCount > 1"
                        class="flex items-center justify-center gap-2 py-2"
                    >
                        <Button
                            variant="outline"
                            size="icon"
                            :disabled="page <= 1 || loadingFilters"
                            @click="goToPage(page - 1)"
                        >
                            <ChevronLeft class="h-4 w-4" />
                        </Button>
                        <span class="text-sm text-muted-foreground">
                            Страница {{ page }} из {{ pageCount }}
                        </span>
                        <Button
                            variant="outline"
                            size="icon"
                            :disabled="page >= pageCount || loadingFilters"
                            @click="goToPage(page + 1)"
                        >
                            <ChevronRight class="h-4 w-4" />
                        </Button>
                    </div>
                </div>

                <div v-else-if="hasActiveFilters && !feedbacksError" class="space-y-2">
                    <p class="text-sm text-muted-foreground">
                        Нет отзывов по выбранным фильтрам (после запроса к API WB
                        <template v-if="feedbacksMeta.brand_filter_active">
                            и фильтра брендов кабинета
                        </template>
                        ).
                    </p>
                    <Button variant="outline" size="sm" @click="clearFilters">
                        Сбросить фильтры
                    </Button>
                </div>

                <div v-else-if="!feedbacksError" class="space-y-3">
                    <p class="text-sm text-muted-foreground">Неотвеченных отзывов нет.</p>
                    <Button @click="refresh">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        Обновить данные с WB
                    </Button>
                </div>
            </div>
        </Card>

        <div class="mt-8">
            <AnsweredReviewsWidget :client-id="client.id" />
        </div>
    </SubscriberLayout>
</template>
