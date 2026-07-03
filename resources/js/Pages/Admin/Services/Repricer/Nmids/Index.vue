<script setup>
import { Head, Link, router } from "@inertiajs/vue3";
import { computed, h, ref } from "vue";
import { actionsColumn, renderRowActions } from "@/lib/tableActions";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import RepricerSubnav from "@/components/admin/RepricerSubnav.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Badge from "@/components/ui/Badge.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    nmids: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const cabinetId = ref(props.filters.cabinet_id ?? "");
const expandedId = ref(null);

const activeBadge = (value) => h(Badge, { variant: value ? "success" : "destructive" }, () => value ? "Да" : "Нет");

const columns = computed(() => [
    { accessorKey: "cabinet.name", header: "Кабинет", cell: ({ row }) => row.original.cabinet?.name ?? "—" },
    {
        id: "user",
        header: "Подписчик",
        cell: ({ row }) => {
            const user = row.original.cabinet?.user;
            const subId = user?.subscriber?.id;
            const label = user ? `${user.name}` : "—";
            if (subId) {
                return h(Link, { href: `/cw-page/subscribers/${subId}`, class: "text-primary hover:underline text-sm" }, () => label);
            }
            return label;
        },
    },
    { accessorKey: "name", header: "Название", cell: ({ row }) => row.original.name },
    { accessorKey: "nmID", header: "nmID", cell: ({ row }) => row.original.nmID },
    { accessorKey: "base_value", header: "Цена", cell: ({ row }) => row.original.base_value },
    { accessorKey: "base_discount", header: "Скидка", cell: ({ row }) => row.original.base_discount },
    { id: "active", header: "Активна", cell: ({ row }) => activeBadge(row.original.active) },
    { id: "status", header: "Статус", cell: ({ row }) => activeBadge(row.original.status) },
    { accessorKey: "created_at", header: "Создан", cell: ({ row }) => row.original.created_at },
    {
        ...actionsColumn,
        cell: ({ row }) => renderRowActions([
            {
                label: expandedId.value === row.original.nmID ? "Скрыть логи" : "Логи",
                onClick: () => toggleLogs(row.original.nmID),
            },
        ]),
    },
]);

const expandedItem = computed(() =>
    (props.nmids.data ?? []).find((item) => item.nmID === expandedId.value) ?? null
);

function applyFilter() {
    router.get("/cw-page/services/repricer/nmids", {
        cabinet_id: cabinetId.value || undefined,
        per_page: props.filters.per_page,
    }, { preserveState: true });
}

function toggleLogs(nmID) {
    expandedId.value = expandedId.value === nmID ? null : nmID;
}
</script>

<template>
    <Head title="Репрайсер — номенклатуры" />

    <AdminLayout title="Номенклатуры" :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Репрайсер — nmID' }]">
        <PageHeader title="Номенклатуры репрайсера" description="Настройки цен по артикулам WB" />
        <RepricerSubnav />

        <Card class="mb-4 p-4">
            <div class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="mb-1 block text-sm">ID кабинета</label>
                    <Input v-model="cabinetId" type="number" placeholder="Фильтр по кабинету" class="w-40" />
                </div>
                <Button @click="applyFilter">Применить</Button>
            </div>
        </Card>

        <Card class="p-4">
            <DataTable :columns="columns" :data="nmids.data ?? []" />
            <div
                v-if="expandedItem"
                class="mt-3 rounded-md border p-3 text-sm"
            >
                <p class="mb-2 font-medium">Логи: {{ expandedItem.name }} ({{ expandedItem.nmID }})</p>
                <div v-if="expandedItem.logs?.length" class="max-h-48 space-y-1 overflow-y-auto text-xs">
                    <div
                        v-for="(log, idx) in expandedItem.logs"
                        :key="idx"
                        :class="log.type === 'error' ? 'text-destructive' : log.type === 'success' ? 'text-green-600' : ''"
                    >
                        <span class="text-muted-foreground">{{ log.created_at }}</span> — {{ log.message }}
                    </div>
                </div>
                <p v-else class="text-xs text-muted-foreground">Логов нет</p>
            </div>
            <div v-if="nmids.last_page > 1" class="mt-4 flex justify-between text-sm text-muted-foreground">
                <span>Страница {{ nmids.current_page }} из {{ nmids.last_page }}</span>
                <div class="flex gap-2">
                    <Button
                        v-if="nmids.current_page > 1"
                        variant="outline"
                        size="sm"
                        @click="router.get('/cw-page/services/repricer/nmids', { ...filters, page: nmids.current_page - 1 })"
                    >
                        Назад
                    </Button>
                    <Button
                        v-if="nmids.current_page < nmids.last_page"
                        variant="outline"
                        size="sm"
                        @click="router.get('/cw-page/services/repricer/nmids', { ...filters, page: nmids.current_page + 1 })"
                    >
                        Далее
                    </Button>
                </div>
            </div>
        </Card>
    </AdminLayout>
</template>