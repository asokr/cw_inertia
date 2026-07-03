<script setup>
import { Head, Link, router } from "@inertiajs/vue3";
import { ref } from "vue";
import { actionsColumn, renderRowActions } from "@/lib/tableActions";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Select from "@/components/ui/Select.vue";
import Badge from "@/components/ui/Badge.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";

const props = defineProps({
    sellerId: { type: String, required: true },
    legalEntity: { type: String, default: null },
    totalRequests: { type: Number, default: 0 },
    uniqueKeys: { type: Number, default: 0 },
    keysList: { type: Array, default: () => [] },
    endpointStats: { type: Array, default: () => [] },
    logs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const localFilters = ref({ ...props.filters });
const keysOpen = ref(false);
const dataOpen = ref(false);
const selectedRequestData = ref("");

const formatNumber = (value) => Number(value ?? 0).toLocaleString("ru-RU");

const maskKey = (key) => {
    if (!key) return "—";
    const trimmed = String(key).replace(/\s+/g, "");
    if (trimmed.length <= 8) return trimmed;
    return `${trimmed.slice(0, 4)}…${trimmed.slice(-4)}`;
};

const formatTime = (value) => {
    if (!value) return "—";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleTimeString("ru-RU", { hour: "2-digit", minute: "2-digit", second: "2-digit" });
};

const truncateEndpoint = (endpoint) => {
    if (!endpoint) return "—";
    return endpoint.length > 50 ? `${endpoint.slice(0, 47)}...` : endpoint;
};

const columns = [
    { accessorKey: "created_at", header: "Время", cell: ({ row }) => formatTime(row.original.created_at) },
    { accessorKey: "method", header: "Метод", cell: ({ row }) => row.original.method },
    { accessorKey: "endpoint", header: "Эндпоинт", cell: ({ row }) => row.original.endpoint },
    { accessorKey: "response_code", header: "Код", cell: ({ row }) => row.original.response_code ?? "—" },
    { accessorKey: "api_key", header: "Ключ", cell: ({ row }) => maskKey(row.original.api_key) },
    {
        ...actionsColumn,
        cell: ({ row }) => {
            const data = row.original.request_data;
            if (!data || !Object.keys(data).length) {
                return "—";
            }
            return renderRowActions([
                { label: "Данные", onClick: () => showRequestData(row.original) },
            ]);
        },
    },
];

function applyFilters() {
    router.get(`/cw-page/wb/api-usage/${props.sellerId}/logs`, {
        ...localFilters.value,
        page: 1,
    }, { preserveState: true });
}

function toggleEndpoint(endpoint) {
    localFilters.value.endpoint = localFilters.value.endpoint === endpoint ? "" : endpoint;
    applyFilters();
}

function changePage(page) {
    router.get(`/cw-page/wb/api-usage/${props.sellerId}/logs`, {
        ...localFilters.value,
        page,
    }, { preserveState: true });
}

function showRequestData(row) {
    selectedRequestData.value = JSON.stringify(row.request_data, null, 2);
    dataOpen.value = true;
}
</script>

<template>
    <Head :title="`WB API Logs — ${sellerId}`" />

    <AdminLayout
        title="Детальные логи WB API"
        :breadcrumbs="[
            { label: 'Админка', href: '/cw-page' },
            { label: 'WB API Usage', href: '/cw-page/wb/api-usage' },
            { label: `Seller ${sellerId}` },
        ]"
    >
        <Link href="/cw-page/wb/api-usage" class="mb-3 inline-block text-sm text-primary hover:underline">
            ← Назад к статистике
        </Link>

        <PageHeader
            :title="`Детальные логи — Seller ${sellerId}`"
            :description="legalEntity ? legalEntity : 'Логи запросов за выбранную дату'"
        />

        <Card class="mb-4 p-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-sm font-medium">Дата</label>
                    <Input v-model="localFilters.date" type="date" @change="applyFilters" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Эндпоинт</label>
                    <Input v-model="localFilters.endpoint" placeholder="Фильтр..." />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Метод</label>
                    <Select v-model="localFilters.method">
                        <option value="">Все</option>
                        <option value="GET">GET</option>
                        <option value="POST">POST</option>
                        <option value="PUT">PUT</option>
                        <option value="PATCH">PATCH</option>
                        <option value="DELETE">DELETE</option>
                    </Select>
                </div>
                <Button @click="applyFilters">Применить</Button>
            </div>
        </Card>

        <div class="mb-4 grid gap-4 md:grid-cols-2">
            <Card class="p-4">
                <div class="text-xs text-muted-foreground">Запросов за день</div>
                <div class="text-2xl font-semibold">{{ formatNumber(totalRequests) }}</div>
            </Card>
            <Card class="cursor-pointer p-4 transition-shadow hover:shadow-md" @click="keysOpen = true">
                <div class="text-xs text-muted-foreground">Уникальных ключей</div>
                <div class="text-2xl font-semibold">{{ formatNumber(uniqueKeys) }}</div>
            </Card>
        </div>

        <div v-if="endpointStats.length" class="mb-4">
            <h3 class="mb-2 text-sm font-semibold">Топ эндпоинтов</h3>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <button
                    v-for="(ep, idx) in endpointStats"
                    :key="idx"
                    type="button"
                    class="rounded-lg border p-3 text-left transition-shadow hover:shadow-md"
                    :class="localFilters.endpoint === ep.endpoint ? 'ring-2 ring-primary' : ''"
                    @click="toggleEndpoint(ep.endpoint)"
                >
                    <div class="mb-1 flex items-center justify-between">
                        <Badge variant="secondary">{{ ep.method }}</Badge>
                        <span class="font-semibold text-primary">{{ formatNumber(ep.count) }}</span>
                    </div>
                    <p class="font-mono text-xs break-all text-muted-foreground">{{ truncateEndpoint(ep.endpoint) }}</p>
                </button>
            </div>
        </div>

        <Card class="p-4">
            <DataTable :columns="columns" :data="logs.data ?? []" />
            <div v-if="logs.last_page > 1" class="mt-4 flex justify-between text-sm text-muted-foreground">
                <span>Страница {{ logs.current_page }} из {{ logs.last_page }}</span>
                <div class="flex gap-2">
                    <Button v-if="logs.current_page > 1" variant="outline" size="sm" @click="changePage(logs.current_page - 1)">Назад</Button>
                    <Button v-if="logs.current_page < logs.last_page" variant="outline" size="sm" @click="changePage(logs.current_page + 1)">Далее</Button>
                </div>
            </div>
        </Card>

        <Dialog v-model:open="dataOpen" title="Данные запроса">
            <pre class="max-h-96 overflow-auto rounded-md bg-muted p-3 font-mono text-xs whitespace-pre-wrap">{{ selectedRequestData }}</pre>
        </Dialog>

        <Dialog v-model:open="keysOpen" title="Использованные API ключи">
            <ul v-if="keysList.length" class="space-y-2">
                <li v-for="(key, idx) in keysList" :key="idx" class="rounded border p-2 font-mono text-sm break-all">
                    {{ key }}
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">Ключи не найдены</p>
        </Dialog>
    </AdminLayout>
</template>