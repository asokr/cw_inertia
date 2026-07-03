<script setup>
import { Link } from "@inertiajs/vue3";
import { onMounted, reactive } from "vue";
import Card from "@/components/ui/Card.vue";
import Input from "@/components/ui/Input.vue";
import WidgetPagination from "@/components/admin/widgets/WidgetPagination.vue";
import { useAdminWidgetApi } from "@/composables/useAdminWidgetApi";

const { getWidget } = useAdminWidgetApi();

const state = reactive({
    loading: false,
    date: new Date().toISOString().slice(0, 10),
    summary: { totalRequests: 0, uniqueKeys: 0, uniqueClients: 0 },
    items: [],
    meta: { total: 0, current_page: 1, last_page: 1 },
    perPage: 10,
});

function formatNumber(value) {
    return Number(value ?? 0).toLocaleString("ru-RU");
}

function maskKey(key) {
    if (!key) return "—";
    const trimmed = String(key).replace(/\s+/g, "");
    if (trimmed.length <= 8) return trimmed;
    return `${trimmed.slice(0, 4)}…${trimmed.slice(-4)}`;
}

function formatDateTime(value) {
    if (!value) return "—";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString("ru-RU");
}

async function fetchStats() {
    state.loading = true;
    try {
        const result = await getWidget("/cw-page/widgets/wb-api-usage", {
            date: state.date,
            per_page: state.perPage,
            page: state.meta.current_page,
        });

        if (result?.success) {
            state.summary.totalRequests = result.data?.total_requests ?? 0;
            state.summary.uniqueKeys = result.data?.unique_keys ?? 0;
            state.summary.uniqueClients = result.data?.unique_clients ?? 0;
            state.items = result.data?.items ?? [];
            state.meta = {
                total: result.meta?.total ?? 0,
                current_page: result.meta?.current_page ?? 1,
                last_page: result.meta?.last_page ?? 1,
            };
        }
    } finally {
        state.loading = false;
    }
}

function handleDateChange() {
    state.meta.current_page = 1;
    fetchStats();
}

function prevPage() {
    if (state.meta.current_page > 1) {
        state.meta.current_page--;
        fetchStats();
    }
}

function nextPage() {
    if (state.meta.current_page < state.meta.last_page) {
        state.meta.current_page++;
        fetchStats();
    }
}

function changeRows() {
    state.meta.current_page = 1;
    fetchStats();
}

onMounted(fetchStats);
</script>

<template>
    <Card class="p-4">
        <div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h3 class="text-lg font-semibold">Статистика запросов к API Wildberries</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    Активные ключи и количество запросов за выбранную дату.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <label class="text-sm font-medium" for="wb-stat-date">Дата</label>
                <Input id="wb-stat-date" v-model="state.date" type="date" @change="handleDateChange" />
            </div>
        </div>

        <div class="mb-6 grid gap-4 md:grid-cols-3">
            <div class="rounded-lg border p-4">
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Всего запросов</p>
                <p class="mt-2 text-2xl font-semibold">{{ formatNumber(state.summary.totalRequests) }}</p>
            </div>
            <div class="rounded-lg border p-4">
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Уникальных ключей</p>
                <p class="mt-2 text-2xl font-semibold">{{ formatNumber(state.summary.uniqueKeys) }}</p>
            </div>
            <div class="rounded-lg border p-4">
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Уникальных клиентов</p>
                <p class="mt-2 text-2xl font-semibold">{{ formatNumber(state.summary.uniqueClients) }}</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-muted-foreground">
                        <th class="px-3 py-2">API ключ</th>
                        <th class="px-3 py-2">Запросов</th>
                        <th class="px-3 py-2">Юр. лицо</th>
                        <th class="px-3 py-2">Seller ID</th>
                        <th class="px-3 py-2">Обновлено</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in state.items" :key="item.id" class="border-b">
                        <td class="px-3 py-2 font-mono">{{ maskKey(item.api_key) }}</td>
                        <td class="px-3 py-2">{{ formatNumber(item.requests_count) }}</td>
                        <td class="px-3 py-2">{{ item.legal_entity || "Не определено" }}</td>
                        <td class="px-3 py-2">
                            <Link
                                v-if="item.seller_id"
                                :href="`/cw-page/wb/api-usage/${item.seller_id}/logs?date=${state.date}`"
                                class="font-medium text-primary hover:underline"
                            >
                                {{ item.seller_id }}
                            </Link>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-3 py-2 text-muted-foreground">{{ formatDateTime(item.legal_entity_synced_at) }}</td>
                    </tr>
                    <tr v-if="!state.items.length">
                        <td colspan="5" class="px-3 py-6 text-center text-muted-foreground">
                            {{ state.loading ? "Загрузка…" : "Нет данных за выбранную дату" }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <WidgetPagination
            :page="state.meta.current_page"
            :total-pages="state.meta.last_page"
            :per-page="state.perPage"
            :per-page-options="[5, 10, 25, 50]"
            :total="state.meta.total"
            :loading="state.loading"
            @update:per-page="state.perPage = $event; changeRows()"
            @prev="prevPage"
            @next="nextPage"
        />
    </Card>
</template>