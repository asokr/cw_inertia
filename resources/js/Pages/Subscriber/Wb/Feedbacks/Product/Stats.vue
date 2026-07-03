<script setup>
import { computed, ref } from "vue";
import { Head, router } from "@inertiajs/vue3";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import StarRating from "@/components/subscriber/wb/feedbacks/StarRating.vue";
import Card from "@/components/ui/Card.vue";
import Select from "@/components/ui/Select.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";

const props = defineProps({
    client: { type: Object, required: true },
    productId: { type: [String, Number], required: true },
    month: { type: String, required: true },
    months: { type: Array, default: () => [] },
    statistics: { type: Object, default: null },
    statisticsMessage: { type: String, default: null },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Управление отзывами", href: "/panel/wb/feedbacks" },
    { label: props.client.name, href: `/panel/wb/feedbacks/clients/${props.client.id}` },
    { label: "Статистика по товару" },
];

const selectedMonth = ref(props.month);
const prosShowAll = ref(false);
const consShowAll = ref(false);
const limit = 6;

const baseUrl = `/panel/wb/feedbacks/clients/${props.client.id}/products/${props.productId}`;

const ratingEntries = computed(() => {
    const dist = props.statistics?.rating_distribution ?? {};
    return [5, 4, 3, 2, 1].map((star) => ({
        star,
        count: dist[String(star)] ?? 0,
    }));
});

const maxRatingCount = computed(() =>
    ratingEntries.value.reduce((max, item) => Math.max(max, item.count), 0),
);

const prosList = computed(() => props.statistics?.pros_cons_data?.pros ?? []);
const consList = computed(() => props.statistics?.pros_cons_data?.cons ?? []);
const prosDisplay = computed(() => (prosShowAll.value ? prosList.value : prosList.value.slice(0, limit)));
const consDisplay = computed(() => (consShowAll.value ? consList.value : consList.value.slice(0, limit)));

function changeMonth(value) {
    selectedMonth.value = value;
    router.get(baseUrl, { month: value }, { preserveScroll: true });
}

function progressValue(count) {
    if (!maxRatingCount.value) return 0;
    return Math.round((count / maxRatingCount.value) * 100);
}
</script>

<template>
    <Head :title="`Статистика товара ${productId}`" />

    <SubscriberLayout title="Статистика по товару" :breadcrumbs="breadcrumbs">
        <ToolPageHeader title="Статистика по товару" :description="`Товар #${productId}`" />

        <div class="mb-6 flex items-center gap-3">
            <span class="text-sm">Выберите месяц:</span>
            <Select
                :model-value="selectedMonth"
                class="max-w-xs"
                @update:model-value="changeMonth"
            >
                <option v-for="item in months" :key="item.value" :value="item.value">
                    {{ item.label }}
                </option>
            </Select>
        </div>

        <template v-if="statistics">
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <Card v-if="statistics.product_image" class="p-4">
                    <p class="mb-2 font-medium">Товар</p>
                    <img :src="statistics.product_image" alt="" class="mx-auto max-h-36 object-contain" />
                </Card>
                <Card class="bg-blue-50/60 p-4">
                    <p class="text-sm text-muted-foreground">Всего отзывов</p>
                    <p class="text-3xl font-bold">{{ statistics.total_reviews ?? 0 }}</p>
                </Card>
                <Card class="bg-amber-50/60 p-4">
                    <p class="text-sm text-muted-foreground">Средний рейтинг</p>
                    <div class="flex items-center gap-2">
                        <span class="text-3xl font-bold">{{ statistics.average_rating ?? "0.00" }}</span>
                        <StarRating :value="Math.round(Number(statistics.average_rating ?? 0))" />
                    </div>
                </Card>
            </div>

            <div class="mt-6 grid gap-4 lg:grid-cols-2">
                <Card v-if="ratingEntries.some((e) => e.count > 0)" class="p-4">
                    <h3 class="mb-3 font-semibold">Распределение по рейтингам</h3>
                    <div class="space-y-2">
                        <div v-for="item in ratingEntries" :key="item.star" class="flex items-center gap-3 text-sm">
                            <span class="w-8 text-right">{{ item.star }}★</span>
                            <div class="h-2 flex-1 rounded-full bg-muted">
                                <div
                                    class="h-2 rounded-full bg-amber-400"
                                    :style="{ width: `${progressValue(item.count)}%` }"
                                />
                            </div>
                            <span class="w-8 text-right">{{ item.count }}</span>
                        </div>
                    </div>
                </Card>

                <Card v-if="statistics.bables?.length" class="bg-purple-50/50 p-4">
                    <h3 class="mb-3 font-semibold">Топ впечатлений</h3>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="item in statistics.bables"
                            :key="item.bable"
                            class="rounded-full bg-purple-600 px-3 py-1 text-xs text-white"
                        >
                            {{ item.bable }} ×{{ item.count }}
                        </span>
                    </div>
                </Card>
            </div>

            <Card v-if="prosList.length || consList.length" class="mt-6 p-4">
                <h3 class="mb-4 font-semibold">Достоинства и недостатки</h3>
                <div class="grid gap-6 md:grid-cols-2">
                    <div>
                        <p class="mb-2 font-medium text-green-700">Достоинства</p>
                        <ul class="space-y-2 text-sm">
                            <li v-for="(text, idx) in prosDisplay" :key="`pro-${idx}`">{{ text }}</li>
                        </ul>
                        <button
                            v-if="prosList.length > limit"
                            type="button"
                            class="mt-2 text-sm text-primary hover:underline"
                            @click="prosShowAll = !prosShowAll"
                        >
                            {{ prosShowAll ? "Свернуть" : `Показать все (${prosList.length})` }}
                        </button>
                    </div>
                    <div>
                        <p class="mb-2 font-medium text-red-700">Недостатки</p>
                        <ul class="space-y-2 text-sm">
                            <li v-for="(text, idx) in consDisplay" :key="`con-${idx}`">{{ text }}</li>
                        </ul>
                        <button
                            v-if="consList.length > limit"
                            type="button"
                            class="mt-2 text-sm text-primary hover:underline"
                            @click="consShowAll = !consShowAll"
                        >
                            {{ consShowAll ? "Свернуть" : `Показать все (${consList.length})` }}
                        </button>
                    </div>
                </div>
            </Card>
        </template>

        <Card v-else class="p-8 text-center text-sm text-muted-foreground">
            {{ statisticsMessage || "Нет данных за выбранный месяц" }}
        </Card>
    </SubscriberLayout>
</template>