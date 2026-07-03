<script setup>
import { Head } from "@inertiajs/vue3";
import { h } from "vue";
import DataTable from "@/components/DataTable.vue";
import Badge from "@/components/ui/Badge.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";

const props = defineProps({
    transactions: { type: Array, default: () => [] },
});

const statusVariant = {
    CONFIRMED: "success",
    CREATE: "secondary",
    CREATED: "secondary",
    FAILED: "destructive",
    CANCELED: "destructive",
    RETURNED: "warning",
};

const columns = [
    {
        accessorKey: "created_at",
        header: "Дата",
        cell: ({ row }) => formatDate(row.original.created_at),
    },
    {
        accessorKey: "description",
        header: "Описание",
        cell: ({ row }) => row.original.description ?? "—",
    },
    {
        accessorKey: "amount",
        header: "Сумма",
        cell: ({ row }) => `${row.original.amount} ₽`,
    },
    {
        accessorKey: "system",
        header: "Система",
        cell: ({ row }) => row.original.system ?? "—",
    },
    {
        accessorKey: "status",
        header: "Статус",
        cell: ({ row }) => {
            const status = row.original.status;
            return h(
                Badge,
                { variant: statusVariant[status] ?? "secondary" },
                () => status ?? "—"
            );
        },
    },
];

function formatDate(value) {
    if (!value) return "—";
    return new Date(value).toLocaleString("ru-RU");
}
</script>

<template>
    <Head title="История платежей" />

    <SubscriberLayout
        title="История платежей"
        :breadcrumbs="[
            { label: 'Панель', href: '/panel' },
            { label: 'Профиль', href: '/panel/user/profile' },
            { label: 'История' },
        ]"
    >
        <DataTable :columns="columns" :data="transactions" empty-text="Платежей пока нет" />
    </SubscriberLayout>
</template>