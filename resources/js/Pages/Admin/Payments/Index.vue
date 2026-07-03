<script setup>
import { Head, Link, router } from "@inertiajs/vue3";
import { h } from "vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import SubscribersSubnav from "@/components/admin/SubscribersSubnav.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Badge from "@/components/ui/Badge.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    payments: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const formatCurrency = (value) => new Intl.NumberFormat("ru-RU", {
    style: "currency",
    currency: "RUB",
}).format(Number(value ?? 0));

const statusLabel = (status) => ({
    CREATED: "Создан",
    FAILED: "Неудачный",
    CONFIRMED: "Подтверждён",
    RETURNED: "Возврат",
}[status] ?? status);

const statusVariant = (status) => ({
    CONFIRMED: "success",
    FAILED: "destructive",
    RETURNED: "secondary",
}[status] ?? "secondary");

const columns = [
    { accessorKey: "created_at", header: "Дата", cell: ({ row }) => row.original.created_at },
    {
        id: "user",
        header: "Подписчик",
        cell: ({ row }) => {
            const user = row.original.user;
            const subscriberId = user?.subscriber?.id;
            const label = `${user?.name ?? "—"} (${user?.email ?? "—"})`;
            if (subscriberId) {
                return h(Link, { href: `/cw-page/subscribers/${subscriberId}`, class: "text-primary hover:underline text-sm" }, () => label);
            }
            return label;
        },
    },
    { accessorKey: "description", header: "Назначение", cell: ({ row }) => row.original.description ?? "—" },
    { accessorKey: "amount", header: "Сумма", cell: ({ row }) => formatCurrency(row.original.amount) },
    { accessorKey: "system", header: "Способ", cell: ({ row }) => row.original.system ?? "—" },
    {
        accessorKey: "status",
        header: "Статус",
        cell: ({ row }) => h(Badge, { variant: statusVariant(row.original.status) }, () => statusLabel(row.original.status)),
    },
];
</script>

<template>
    <Head title="Оплаты" />

    <AdminLayout title="Оплаты" :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Оплаты' }]">
        <PageHeader title="История пополнений" description="Платежи подписчиков через платёжные системы" />

        <SubscribersSubnav />

        <Card class="p-4">
            <DataTable :columns="columns" :data="payments.data ?? []" />
            <div v-if="payments.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                <span>Страница {{ payments.current_page }} из {{ payments.last_page }} ({{ payments.total }})</span>
                <div class="flex gap-2">
                    <Button
                        v-if="payments.current_page > 1"
                        variant="outline"
                        size="sm"
                        @click="router.get('/cw-page/payments', { ...filters, page: payments.current_page - 1 })"
                    >
                        Назад
                    </Button>
                    <Button
                        v-if="payments.current_page < payments.last_page"
                        variant="outline"
                        size="sm"
                        @click="router.get('/cw-page/payments', { ...filters, page: payments.current_page + 1 })"
                    >
                        Далее
                    </Button>
                </div>
            </div>
        </Card>
    </AdminLayout>
</template>