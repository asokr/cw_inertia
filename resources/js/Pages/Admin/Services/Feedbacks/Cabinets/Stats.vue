<script setup>
import { Head, router } from "@inertiajs/vue3";
import { ref } from "vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import FeedbacksSubnav from "@/components/admin/FeedbacksSubnav.vue";
import Button from "@/components/ui/Button.vue";
import Select from "@/components/ui/Select.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    cabinet: { type: Object, required: true },
    stats: { type: Object, default: null },
    statDate: { type: String, default: null },
    availableDates: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const statType = ref(props.filters.stat_type ?? "weekly");
const selectedDate = ref(props.filters.date ?? "");

const statTypes = [
    { value: "weekly", label: "Неделя" },
    { value: "monthly", label: "Месяц" },
    { value: "half_year", label: "Полгода" },
    { value: "yearly", label: "Год" },
];

const statData = () => props.stats?.stat_data ?? props.stats ?? {};

function applyFilters() {
    router.get(`/cw-page/services/feedbacks/cabinets/${props.cabinet.id}/stats`, {
        stat_type: statType.value,
        date: selectedDate.value || undefined,
    }, { preserveState: true });
}

function recalculate() {
    router.post(`/cw-page/services/feedbacks/cabinets/${props.cabinet.id}/recalculate`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Статистика: ${cabinet.name}`" />

    <AdminLayout
        :title="cabinet.name"
        :breadcrumbs="[
            { label: 'Админка', href: '/cw-page' },
            { label: 'Отзывы', href: '/cw-page/services/feedbacks/cabinets' },
            { label: cabinet.name },
        ]"
    >
        <PageHeader :title="`Статистика: ${cabinet.name}`" :description="statDate ? `Период: ${statDate}` : undefined">
            <template #actions>
                <Button variant="outline" @click="recalculate">Пересчитать</Button>
            </template>
        </PageHeader>

        <FeedbacksSubnav />

        <Card class="mb-4 p-4">
            <div class="flex flex-wrap gap-3">
                <Select v-model="statType" class="w-40" @change="applyFilters">
                    <option v-for="t in statTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                </Select>
                <Select
                    v-if="availableDates.length && (statType === 'weekly' || statType === 'monthly')"
                    v-model="selectedDate"
                    class="w-48"
                    @change="applyFilters"
                >
                    <option value="">Последняя дата</option>
                    <option v-for="d in availableDates" :key="d" :value="d">{{ d }}</option>
                </Select>
            </div>
        </Card>

        <div v-if="stats" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card class="p-4">
                <p class="text-sm text-muted-foreground">Всего отзывов</p>
                <p class="text-2xl font-semibold">{{ statData().total_reviews ?? 0 }}</p>
            </Card>
            <Card class="p-4">
                <p class="text-sm text-muted-foreground">Средняя оценка</p>
                <p class="text-2xl font-semibold">{{ statData().average_rating ?? "—" }}</p>
            </Card>
            <Card class="p-4">
                <p class="text-sm text-muted-foreground">С фото</p>
                <p class="text-2xl font-semibold">{{ statData().photo_stats?.with_photos ?? 0 }}</p>
            </Card>
            <Card class="p-4">
                <p class="text-sm text-muted-foreground">Без фото</p>
                <p class="text-2xl font-semibold">{{ statData().photo_stats?.without_photos ?? 0 }}</p>
            </Card>
        </div>

        <Card v-else class="p-8 text-center text-sm text-muted-foreground">
            Нет данных за выбранный период
        </Card>

        <Card v-if="statData().categories?.length" class="mt-4 p-4">
            <h3 class="mb-3 font-medium">Категории</h3>
            <div class="space-y-2 text-sm">
                <div v-for="cat in statData().categories" :key="cat.subject_name" class="flex justify-between border-b pb-2">
                    <span>{{ cat.subject_name }}</span>
                    <span class="text-muted-foreground">{{ cat.total_reviews }} отз. · ★ {{ cat.average_rating ?? "—" }}</span>
                </div>
            </div>
        </Card>
    </AdminLayout>
</template>