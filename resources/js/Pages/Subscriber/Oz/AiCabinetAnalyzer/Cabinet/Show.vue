<script setup>
import { computed, unref } from "vue";
import { Head } from "@inertiajs/vue3";
import { CircleHelp } from "lucide-vue-next";
import AiAnalysisSection from "@/components/subscriber/oz/ai-cabinet-analyzer/AiAnalysisSection.vue";
import ProductsTable from "@/components/subscriber/oz/ai-cabinet-analyzer/ProductsTable.vue";
import ReportRunPanel from "@/components/subscriber/oz/ai-cabinet-analyzer/ReportRunPanel.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useAiCabinetReportPoll } from "@/composables/useAiCabinetReportPoll";
import { useFlashToast } from "@/composables/useFlashToast";

const SELLER_RATING_STATUS_LABELS = {
    OK: "норма",
    WARNING: "внимание",
    CRITICAL: "критично",
};

/** Сырой UNKNOWN_STATUS с API не показываем; остальные статусы — понятными словами. */
function sellerRatingStatusLabel(status) {
    if (status === null || status === undefined || status === "") {
        return null;
    }
    const key = String(status).trim().toUpperCase();
    if (key === "UNKNOWN_STATUS" || key === "UNKNOWN") {
        return null;
    }

    return SELLER_RATING_STATUS_LABELS[key] ?? null;
}

function formatSellerRatingValue(item) {
    const raw = item?.current_value;
    if (raw === null || raw === undefined || raw === "") {
        return null;
    }
    const numeric = Number(raw);
    if (!Number.isFinite(numeric)) {
        return String(raw);
    }

    const valueType = String(item?.value_type || "").toUpperCase();
    const name = String(item?.name || "");
    const isPercent = valueType === "PERCENT"
        || (valueType !== "INDEX" && valueType !== "TIME" && valueType !== "RATIO" && /процент/i.test(name));

    if (isPercent) {
        const percent = Math.abs(numeric) <= 1 ? numeric * 100 : numeric;
        const rounded = Number.isInteger(percent) ? percent : Number(percent.toFixed(1));

        return `${rounded}%`;
    }

    if (Number.isInteger(numeric)) {
        return String(numeric);
    }

    return String(Number(numeric.toFixed(4)));
}

const props = defineProps({
    cabinet: { type: Object, required: true },
    report: { type: Object, default: null },
    meta: { type: Object, default: null },
    products: { type: Array, default: () => [] },
    productsMeta: { type: Object, default: () => ({}) },
    productFilters: { type: Object, default: () => ({}) },
    templates: { type: Array, default: () => [] },
    analyses: { type: Array, default: () => [] },
    analysesMeta: { type: Object, default: () => ({}) },
    defaultPeriod: { type: Object, default: () => ({}) },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "ИИ анализ кабинета Ozon", href: "/panel/oz/ai-cabinet-analyzer" },
    { label: props.cabinet.name },
];

const { showError, watchPropToast } = useFlashToast();

const poll = useAiCabinetReportPoll({
    onFailed: (message) => {
        showError(message);
    },
});

const isReportPolling = computed(() => Boolean(unref(poll.isPolling)));
const isReportLongRunning = computed(() => Boolean(unref(poll.timedOut)));

const showUrl = computed(() => `/panel/oz/ai-cabinet-analyzer`);
const startUrl = computed(() => `${showUrl.value}/reports`);
const isReportDone = computed(() => props.report?.status === "done");
const hasMeta = computed(() => Boolean(props.meta && Object.keys(props.meta).length > 0));
const warnings = computed(() => (Array.isArray(props.meta?.warnings) ? props.meta.warnings : []));
const productsCount = computed(() => props.meta?.products_count ?? props.productsMeta?.total ?? null);
const sellerRating = computed(() => {
    const summary = props.meta?.seller_rating;
    if (!summary || typeof summary !== "object") {
        return null;
    }
    const items = [];
    for (const group of Array.isArray(summary.groups) ? summary.groups : []) {
        for (const item of Array.isArray(group.items) ? group.items : []) {
            if (item?.name) {
                items.push({
                    ...item,
                    displayValue: formatSellerRatingValue(item),
                    statusLabel: sellerRatingStatusLabel(item.status),
                });
            }
        }
    }
    return {
        premium: Boolean(summary.premium),
        premiumPlus: Boolean(summary.premium_plus),
        penaltyExceeded: Boolean(summary.penalty_score_exceeded),
        items: items.slice(0, 8),
    };
});

function onPollingStart() {
    poll.start();
}

watchPropToast(() => warnings.value, "default");
</script>

<template>
    <Head :title="`ИИ анализ кабинета Ozon — ${cabinet.name}`" />

    <SubscriberLayout :title="cabinet.name" :breadcrumbs="breadcrumbs">
        <ToolPageHeader
            title="Анализ кабинета Ozon"
            :description="cabinet.name"
        />

        <div class="space-y-8 md:space-y-10">
            <ReportRunPanel
                :report="report"
                :default-period="defaultPeriod"
                :is-polling="isReportPolling"
                :timed-out="isReportLongRunning"
                :start-url="startUrl"
                :refresh-url="showUrl"
                @polling-start="onPollingStart"
            />

            <AiAnalysisSection
                :report="report"
                :templates="templates"
                :analyses="analyses"
                :analyses-meta="analysesMeta"
                :show-url="showUrl"
            />

            <template v-if="hasMeta">
                <div v-if="isReportDone" class="space-y-3">
                    <p v-if="productsCount !== null" class="text-sm text-muted-foreground">
                        Товаров: <span class="font-medium text-foreground">{{ productsCount }}</span>
                    </p>
                    <div
                        v-if="sellerRating"
                        class="overflow-visible rounded-lg border p-4 text-sm"
                    >
                        <div class="mb-2 flex items-center gap-1.5">
                            <p class="font-medium">Рейтинги продавца</p>
                            <span
                                class="group/hint relative inline-flex shrink-0"
                                tabindex="0"
                                aria-label="Что означают рейтинги продавца"
                            >
                                <CircleHelp
                                    class="h-4 w-4 cursor-help text-muted-foreground/70 transition-colors group-hover/hint:text-foreground group-focus-within/hint:text-foreground"
                                    aria-hidden="true"
                                />
                                <span
                                    role="tooltip"
                                    class="pointer-events-none invisible absolute left-0 top-full z-50 mt-1.5 w-80 max-w-[calc(100vw-3rem)] rounded-md bg-zinc-900 px-3 py-2.5 text-left text-xs font-normal leading-relaxed text-white opacity-0 shadow-lg transition-opacity group-hover/hint:visible group-hover/hint:opacity-100 group-focus-within/hint:visible group-focus-within/hint:opacity-100"
                                >
                                    Показатели качества вашего магазина в Ozon. По ним маркетплейс решает, насколько охотно показывать ваши товары покупателям.
                                    <br><br>
                                    Premium и Premium Plus — платные подписки Ozon с дополнительными возможностями в кабинете.
                                    <br><br>
                                    Штрафные баллы — если лимит превышен, часть функций кабинета могут ограничить.
                                    <br><br>
                                    Цветные зоны по индексу цен — доля товаров с выгодной (зелёная и супервыгодная), средней (жёлтая) и завышенной (красная) ценой относительно рынка. Чем больше товаров в зелёной зоне, тем лучше они продвигаются.
                                    <br><br>
                                    Оценка товаров — средняя оценка карточек от покупателей.
                                    <br><br>
                                    Просрочки отгрузки — доля заказов, которые отправили позже срока.
                                    <br><br>
                                    Рейтинг по прогрессивной шкале — общий показатель качества работы продавца.
                                    <br><br>
                                    Жалобы по FBO — претензии покупателей по товарам, которые хранятся на складе Ozon.
                                </span>
                            </span>
                        </div>
                        <p class="mb-3 text-xs text-muted-foreground">
                            Premium: {{ sellerRating.premium ? "да" : "нет" }}
                            · Premium Plus: {{ sellerRating.premiumPlus ? "да" : "нет" }}
                            · Штрафные баллы превышены: {{ sellerRating.penaltyExceeded ? "да" : "нет" }}
                        </p>
                        <ul v-if="sellerRating.items.length" class="grid gap-1 sm:grid-cols-2">
                            <li
                                v-for="(item, index) in sellerRating.items"
                                :key="`${item.rating || item.name}-${index}`"
                                class="text-muted-foreground"
                            >
                                <span class="text-foreground">{{ item.name }}</span>
                                <span v-if="item.displayValue">
                                    — {{ item.displayValue }}
                                </span>
                                <span v-if="item.statusLabel">
                                    ({{ item.statusLabel }})
                                </span>
                            </li>
                        </ul>
                    </div>
                    <ProductsTable
                        :show-url="showUrl"
                        :report-id="report?.id"
                        :items="products"
                        :meta="productsMeta"
                        :filters="productFilters"
                    />
                </div>
            </template>
        </div>
    </SubscriberLayout>
</template>
