<script setup>
import { Link } from "@inertiajs/vue3";
import { computed, onMounted, reactive } from "vue";
import Card from "@/components/ui/Card.vue";
import WidgetPagination from "@/components/admin/widgets/WidgetPagination.vue";
import { useAdminWidgetApi } from "@/composables/useAdminWidgetApi";

const props = defineProps({
    rows: { type: Number, default: 5 },
});

const { postWidget } = useAdminWidgetApi();

const state = reactive({
    subscriptions: [],
    loading: false,
    total: 0,
    page: 1,
    rowsPerPage: props.rows,
    sortField: "renewed_at",
    sortOrder: "desc",
});

const totalPages = computed(() => Math.max(1, Math.ceil(state.total / state.rowsPerPage)));

function numberFormat(value) {
    return Number(value || 0).toLocaleString("ru-RU");
}

function formatDateTime(value) {
    if (!value) return "—";
    return new Date(value).toLocaleString("ru-RU", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
}

function balanceValue(item) {
    return item?.subscriber?.user?.balances?.value || 0;
}

function isCouponRecent(usedAt) {
    if (!usedAt) return false;
    const diffInDays = (Date.now() - new Date(usedAt).getTime()) / (1000 * 60 * 60 * 24);
    return diffInDays <= 30;
}

function changeSort(field) {
    if (state.sortField === field) {
        state.sortOrder = state.sortOrder === "asc" ? "desc" : "asc";
    } else {
        state.sortField = field;
        state.sortOrder = "asc";
    }
    state.page = 1;
    fetchData();
}

async function fetchData() {
    state.loading = true;
    try {
        const result = await postWidget(
            "/cw-page/widgets/last-subscriptions",
            {
                rows: state.rowsPerPage,
                sortField: state.sortField,
                sortOrder: state.sortOrder === "asc" ? "1" : "-1",
            },
            { page: state.page },
        );

        if (result?.success) {
            state.subscriptions = result.data?.data ?? [];
            state.total = result.data?.total ?? 0;
        } else {
            state.subscriptions = [];
            state.total = 0;
        }
    } finally {
        state.loading = false;
    }
}

function prevPage() {
    if (state.page > 1) {
        state.page--;
        fetchData();
    }
}

function nextPage() {
    if (state.page < totalPages.value) {
        state.page++;
        fetchData();
    }
}

function changeRows() {
    state.page = 1;
    fetchData();
}

onMounted(fetchData);
</script>

<template>
    <Card class="p-4">
        <h3 class="mb-4 text-lg font-semibold">Последние продления</h3>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-muted-foreground">
                        <th class="px-3 py-2">Подписчик</th>
                        <th class="px-3 py-2">Телефон</th>
                        <th class="px-3 py-2">Дата продления</th>
                        <th class="cursor-pointer px-3 py-2" @click="changeSort('start_date')">Дата начала</th>
                        <th class="cursor-pointer px-3 py-2" @click="changeSort('end_date')">Дата завершения</th>
                        <th class="px-3 py-2">Баланс</th>
                        <th class="px-3 py-2">Тариф</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in state.subscriptions" :key="item.id" class="border-b">
                        <td class="px-3 py-2">
                            <Link
                                :href="`/cw-page/subscribers/${item.subscriber?.id}`"
                                class="text-primary hover:underline"
                            >
                                {{ item.subscriber?.user?.name }} ({{ item.subscriber?.user?.email }})
                            </Link>
                        </td>
                        <td class="px-3 py-2">{{ item.subscriber?.user?.phone || "—" }}</td>
                        <td class="px-3 py-2">{{ formatDateTime(item.renewed_at) }}</td>
                        <td class="px-3 py-2">{{ item.start_date || "—" }}</td>
                        <td class="px-3 py-2">{{ item.end_date || "—" }}</td>
                        <td class="px-3 py-2">{{ numberFormat(balanceValue(item)) }} ₽</td>
                        <td class="px-3 py-2">
                            <span :title="item.transaction_description">
                                {{ item.plan?.name }}
                                <span v-if="item.plan?.price">({{ numberFormat(item.plan.price) }} ₽)</span>
                            </span>
                            <span
                                v-if="item.coupon_usage?.coupon?.code && isCouponRecent(item.coupon_usage.used_at)"
                                class="ml-2 rounded-full bg-muted px-2 py-0.5 text-xs"
                            >
                                {{ item.coupon_usage.coupon.code }}
                            </span>
                        </td>
                    </tr>
                    <tr v-if="!state.subscriptions.length">
                        <td colspan="7" class="px-3 py-6 text-center text-muted-foreground">
                            {{ state.loading ? "Загрузка…" : "Нет данных" }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <WidgetPagination
            :page="state.page"
            :total-pages="totalPages"
            :per-page="state.rowsPerPage"
            :total="state.total"
            :loading="state.loading"
            @update:per-page="state.rowsPerPage = $event; changeRows()"
            @prev="prevPage"
            @next="nextPage"
        />
    </Card>
</template>