<script setup>
import { Head, router } from "@inertiajs/vue3";
import { ref } from "vue";
import { actionsColumn, renderRowActions } from "@/lib/tableActions";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    stats: { type: Object, required: true },
    summary: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const localFilters = ref({ ...props.filters });

const formatNumber = (value) => Number(value ?? 0).toLocaleString("ru-RU");

const maskKey = (key) => {
    if (!key) return "—";
    const trimmed = String(key).replace(/\s+/g, "");
    if (trimmed.length <= 8) return trimmed;
    return `${trimmed.slice(0, 4)}…${trimmed.slice(-4)}`;
};

const formatDateTime = (value) => {
    if (!value) return "—";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString("ru-RU");
};

const columns = [
    {
        accessorKey: "api_key",
        header: "API ключ",
        cell: ({ row }) => maskKey(row.original.api_key),
    },
    {
        accessorKey: "requests_count",
        header: "Запросов",
        cell: ({ row }) => formatNumber(row.original.requests_count),
    },
    {
        accessorKey: "legal_entity",
        header: "Юр. лицо",
        cell: ({ row }) => row.original.legal_entity || "Не определено",
    },
    {
        id: "seller_id",
        header: "Seller ID",
        cell: ({ row }) => row.original.seller_id || "—",
    },
    {
        accessorKey: "legal_entity_synced_at",
        header: "Обновлено",
        cell: ({ row }) => formatDateTime(row.original.legal_entity_synced_at),
    },
    {
        ...actionsColumn,
        cell: ({ row }) => {
            const sellerId = row.original.seller_id;
            if (!sellerId) return "—";
            return renderRowActions([
                {
                    label: "Логи",
                    href: `/cw-page/wb/api-usage/${sellerId}/logs?date=${localFilters.value.date}`,
                },
            ]);
        },
    },
];

function applyFilters() {
    router.get("/cw-page/wb/api-usage", { ...localFilters.value, page: 1 }, { preserveState: true });
}

function changePage(page) {
    router.get("/cw-page/wb/api-usage", { ...localFilters.value, page }, { preserveState: true });
}
</script>

<template>
    <Head title="WB API Usage" />

    <AdminLayout
        title="WB API Usage"
        :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'WB API Usage' }]"
    >
        <PageHeader
            title="Статистика запросов к API Wildberries"
            description="Активные ключи и количество запросов за выбранную дату"
        />

        <Card class="mb-4 p-4">
            <div class="grid gap-3 md:grid-cols-4">
                <div>
                    <label class="mb-1 block text-sm font-medium">Дата</label>
                    <Input v-model="localFilters.date" type="date" @change="applyFilters" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Юр. лицо</label>
                    <Input v-model="localFilters.legal_entity" placeholder="Фильтр..." />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Seller ID</label>
                    <Input v-model="localFilters.seller_id" placeholder="ID продавца" />
                </div>
                <div class="flex items-end">
                    <Button @click="applyFilters">Применить</Button>
                </div>
            </div>
        </Card>

        <div class="mb-4 grid gap-4 md:grid-cols-3">
            <Card class="p-4">
                <div class="text-xs text-muted-foreground">Всего запросов</div>
                <div class="text-2xl font-semibold">{{ formatNumber(summary.total_requests) }}</div>
            </Card>
            <Card class="p-4">
                <div class="text-xs text-muted-foreground">Уникальных ключей</div>
                <div class="text-2xl font-semibold">{{ formatNumber(summary.unique_keys) }}</div>
            </Card>
            <Card class="p-4">
                <div class="text-xs text-muted-foreground">Уникальных клиентов</div>
                <div class="text-2xl font-semibold">{{ formatNumber(summary.unique_clients) }}</div>
            </Card>
        </div>

        <Card class="p-4">
            <DataTable :columns="columns" :data="stats.data ?? []" />
            <div v-if="stats.last_page > 1" class="mt-4 flex justify-between text-sm text-muted-foreground">
                <span>Страница {{ stats.current_page }} из {{ stats.last_page }} ({{ stats.total }})</span>
                <div class="flex gap-2">
                    <Button
                        v-if="stats.current_page > 1"
                        variant="outline"
                        size="sm"
                        @click="changePage(stats.current_page - 1)"
                    >
                        Назад
                    </Button>
                    <Button
                        v-if="stats.current_page < stats.last_page"
                        variant="outline"
                        size="sm"
                        @click="changePage(stats.current_page + 1)"
                    >
                        Далее
                    </Button>
                </div>
            </div>
        </Card>
    </AdminLayout>
</template>