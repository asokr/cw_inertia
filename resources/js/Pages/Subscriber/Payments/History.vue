<script setup>
import { Head } from "@inertiajs/vue3";
import { h } from "vue";
import DataTable from "@/components/DataTable.vue";
import Badge from "@/components/ui/Badge.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { formatPaymentStatusLabel, paymentStatusBadgeVariant } from "@/utils/paymentStatus";

defineProps({
    transactions: { type: Array, default: () => [] },
});

const columns = [
    {
        accessorKey: "created_at",
        header: "Дата",
        // Дата уже в формате d.m.Y H:i с модели — Date.parse его не понимает
        cell: ({ row }) => row.original.created_at || "—",
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
        accessorKey: "status",
        header: "Статус",
        cell: ({ row }) => {
            const status = row.original.status;
            return h(
                Badge,
                { variant: paymentStatusBadgeVariant(status) },
                () => formatPaymentStatusLabel(status)
            );
        },
    },
];
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
