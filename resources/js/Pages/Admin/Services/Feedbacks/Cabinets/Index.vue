<script setup>
import { Head, Link } from "@inertiajs/vue3";
import { h } from "vue";
import { actionsColumn, renderRowActions } from "@/lib/tableActions";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import FeedbacksSubnav from "@/components/admin/FeedbacksSubnav.vue";
import DataTable from "@/components/DataTable.vue";
import Badge from "@/components/ui/Badge.vue";
import Card from "@/components/ui/Card.vue";

defineProps({
    cabinets: { type: Array, default: () => [] },
});

const boolBadge = (value) => h(Badge, { variant: value ? "success" : "destructive" }, () => value ? "ВКЛ" : "ВЫКЛ");

const columns = [
    {
        id: "subscriber",
        header: "Подписчик",
        cell: ({ row }) => {
            const user = row.original.subscriber?.user;
            const subId = row.original.subscriber_id;
            const label = user ? `${user.name} (${user.email})` : `ID ${subId}`;
            if (subId) {
                return h(Link, { href: `/cw-page/subscribers/${subId}`, class: "text-primary hover:underline text-sm" }, () => label);
            }
            return label;
        },
    },
    { accessorKey: "name", header: "Кабинет", cell: ({ row }) => row.original.name },
    { accessorKey: "brands", header: "Бренды", cell: ({ row }) => row.original.brands || "Все бренды" },
    { id: "bot", header: "Шаблоны", cell: ({ row }) => boolBadge(row.original.bot_status) },
    { id: "ai", header: "AI", cell: ({ row }) => boolBadge(row.original.ai_status) },
    {
        id: "ratings",
        header: "AI рейтинг",
        cell: ({ row }) => (row.original.ai_ratings ?? []).join(", ") || "—",
    },
    {
        ...actionsColumn,
        cell: ({ row }) => renderRowActions([
            { label: "Статистика", href: `/cw-page/services/feedbacks/cabinets/${row.original.id}/stats` },
        ]),
    },
];
</script>

<template>
    <Head title="Кабинеты отзывов" />

    <AdminLayout title="Отзывы" :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Отзывы — кабинеты' }]">
        <PageHeader title="Кабинеты WB Отзывов" description="Все подключённые кабинеты в системе" />
        <FeedbacksSubnav />
        <Card class="p-4">
            <DataTable :columns="columns" :data="cabinets" />
        </Card>
    </AdminLayout>
</template>