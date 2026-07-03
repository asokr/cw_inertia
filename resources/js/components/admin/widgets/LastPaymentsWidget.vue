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
    payments: [],
    loading: false,
    total: 0,
    page: 1,
    rowsPerPage: props.rows,
    sortField: "created_at",
    sortOrder: "desc",
});

const totalPages = computed(() => Math.max(1, Math.ceil(state.total / state.rowsPerPage)));

function statusClass(status) {
    return ({
        CREATED: "bg-blue-100 text-blue-800",
        FAILED: "bg-red-100 text-red-800",
        CONFIRMED: "bg-green-100 text-green-800",
        RETURNED: "bg-yellow-100 text-yellow-800",
        CANCELED: "bg-gray-100 text-gray-800",
    })[status] ?? "bg-muted text-muted-foreground";
}

function statusLabel(status) {
    return ({
        CREATED: "Создан",
        FAILED: "Неудачный",
        CONFIRMED: "Подтверждён",
        RETURNED: "Возврат",
        CANCELED: "Отменён",
    })[status] ?? status;
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
            "/cw-page/widgets/last-payments",
            {
                rows: state.rowsPerPage,
                sortField: state.sortField,
                sortOrder: state.sortOrder === "asc" ? "1" : "-1",
            },
            { page: state.page },
        );

        if (result?.success) {
            state.payments = result.data?.data ?? [];
            state.total = result.data?.total ?? 0;
        } else {
            state.payments = [];
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
        <h3 class="mb-4 text-lg font-semibold">Пополнения</h3>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-muted-foreground">
                        <th class="cursor-pointer px-3 py-2" @click="changeSort('created_at')">Дата создания</th>
                        <th class="px-3 py-2">Подписчик</th>
                        <th class="cursor-pointer px-3 py-2" @click="changeSort('description')">Назначение</th>
                        <th class="px-3 py-2">Сумма</th>
                        <th class="px-3 py-2">Статус</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in state.payments" :key="item.id" class="border-b">
                        <td class="px-3 py-2 whitespace-nowrap" :title="item.created_at?.split(' ')[1]">
                            {{ item.created_at?.split(" ")[0] }}
                        </td>
                        <td class="px-3 py-2">
                            <Link
                                v-if="item.user?.subscriber?.id"
                                :href="`/cw-page/subscribers/${item.user.subscriber.id}`"
                                class="text-primary hover:underline"
                            >
                                {{ item.user.name }} ({{ item.user.email }})
                            </Link>
                            <span v-else>{{ item.user?.name }} ({{ item.user?.email }})</span>
                        </td>
                        <td class="px-3 py-2">{{ item.description }}</td>
                        <td class="px-3 py-2">{{ item.amount }} ₽</td>
                        <td class="px-3 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(item.status)">
                                {{ statusLabel(item.status) }}
                            </span>
                        </td>
                    </tr>
                    <tr v-if="!state.payments.length">
                        <td colspan="5" class="px-3 py-6 text-center text-muted-foreground">
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