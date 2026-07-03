<script setup>
import { Head, router } from "@inertiajs/vue3";
import { h, ref } from "vue";
import { actionsColumn, renderRowActions } from "@/lib/tableActions";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import SubscribersSubnav from "@/components/admin/SubscribersSubnav.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Select from "@/components/ui/Select.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    subscribers: { type: Object, required: true },
    plans: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    searchMode: { type: Boolean, default: false },
});

const search = ref(props.filters.search ?? "");
const planId = ref(props.filters.plan_id ?? "");

const formatCurrency = (amount) => new Intl.NumberFormat("ru-RU", {
    style: "currency",
    currency: "RUB",
    maximumFractionDigits: 2,
}).format(Number(amount ?? 0));

const balanceValue = (balances) => {
    if (!balances) return 0;
    if (typeof balances === "object" && "value" in balances) return balances.value;
    return balances?.[0]?.value ?? 0;
};

const columns = [
    {
        accessorKey: "user.name",
        header: "Имя",
        cell: ({ row }) => row.original.user?.name ?? "—",
    },
    {
        id: "contact",
        header: "Почта / Телефон",
        cell: ({ row }) => {
            const user = row.original.user;
            return h("div", { class: "text-xs" }, [
                h("div", user?.email ?? "—"),
                h("div", { class: "text-muted-foreground" }, user?.phone ?? "—"),
            ]);
        },
    },
    {
        id: "subscriptions",
        header: "Подписки",
        cell: ({ row }) => {
            const subs = row.original.subscriptions ?? [];
            if (!subs.length) return "Не выбран";
            return h("div", { class: "text-xs space-y-0.5" }, subs.map((s) =>
                h("div", `${s.plan?.name ?? "—"} до ${s.end_date ?? "—"}`)
            ));
        },
    },
    {
        id: "balance",
        header: "Баланс",
        cell: ({ row }) => formatCurrency(balanceValue(row.original.user?.balances)),
    },
    {
        ...actionsColumn,
        cell: ({ row }) => renderRowActions([
            { label: "Открыть", href: `/cw-page/subscribers/${row.original.id}` },
        ]),
    },
];

function applyFilters() {
    const params = {
        plan_id: planId.value || undefined,
        per_page: props.filters.per_page,
        sort_field: props.filters.sort_field,
        sort_order: props.filters.sort_order,
    };

    if (search.value && search.value.length >= 2) {
        router.get("/cw-page/subscribers/search", { q: search.value, ...params }, { preserveState: true });
        return;
    }

    router.get("/cw-page/subscribers", params, { preserveState: true });
}
</script>

<template>
    <Head title="Подписчики" />

    <AdminLayout
        title="Подписчики"
        :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Подписчики' }]"
    >
        <PageHeader
            title="Подписчики"
            :description="searchMode ? 'Результаты поиска' : 'Список подписчиков и их подписок'"
        />

        <SubscribersSubnav />

        <Card class="mb-4 p-4">
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm">Поиск</label>
                    <Input v-model="search" placeholder="Имя, email (мин. 2 символа)" @keyup.enter="applyFilters" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Тариф</label>
                    <Select v-model="planId">
                        <option value="">Все</option>
                        <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                    </Select>
                </div>
                <div class="flex items-end">
                    <Button @click="applyFilters">Применить</Button>
                </div>
            </div>
        </Card>

        <Card class="p-4">
            <DataTable :columns="columns" :data="subscribers.data ?? []" />
            <div v-if="subscribers.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                <span>Страница {{ subscribers.current_page }} из {{ subscribers.last_page }} ({{ subscribers.total }})</span>
                <div class="flex gap-2">
                    <Button
                        v-if="subscribers.current_page > 1"
                        variant="outline"
                        size="sm"
                        @click="router.get('/cw-page/subscribers', { ...filters, page: subscribers.current_page - 1 })"
                    >
                        Назад
                    </Button>
                    <Button
                        v-if="subscribers.current_page < subscribers.last_page"
                        variant="outline"
                        size="sm"
                        @click="router.get('/cw-page/subscribers', { ...filters, page: subscribers.current_page + 1 })"
                    >
                        Далее
                    </Button>
                </div>
            </div>
        </Card>
    </AdminLayout>
</template>