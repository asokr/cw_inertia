<script setup>
import { Head, Link } from "@inertiajs/vue3";
import { h } from "vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import AiCabinetSubnav from "@/components/admin/AiCabinetSubnav.vue";
import DataTable from "@/components/DataTable.vue";
import Card from "@/components/ui/Card.vue";

defineProps({
    cabinets: { type: Array, default: () => [] },
});

const formatDate = (value) => value ? new Date(value).toLocaleString("ru-RU") : "—";

const columns = [
    {
        id: "owner",
        header: "Пользователь / Подписчик",
        cell: ({ row }) => {
            const subId = row.original.subscriber_id;
            const label = row.original.owner ?? "—";
            if (subId) {
                return h("div", { class: "text-sm" }, [
                    h(Link, { href: `/cw-page/subscribers/${subId}`, class: "font-medium text-primary hover:underline" }, () => label),
                    h("div", { class: "text-xs text-muted-foreground" }, `User ID: ${row.original.user_id} · Каб. ID: ${row.original.id}`),
                ]);
            }
            return h("div", { class: "text-sm" }, [
                h("div", { class: "font-medium" }, label),
                h("div", { class: "text-xs text-muted-foreground" }, `User ID: ${row.original.user_id} · Каб. ID: ${row.original.id}`),
            ]);
        },
    },
    {
        accessorKey: "name",
        header: "Название кабинета",
        cell: ({ row }) => row.original.name || "Без названия",
    },
    {
        accessorKey: "created_at",
        header: "Создан",
        cell: ({ row }) => formatDate(row.original.created_at),
    },
];
</script>

<template>
    <Head title="ИИ-анализ — кабинеты" />

    <AdminLayout
        title="ИИ-анализ кабинета"
        :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'ИИ-анализ — кабинеты' }]"
    >
        <PageHeader
            title="Кабинеты Profit Analyzer"
            description="WB-кабинеты с подключённым ИИ-анализом"
        />
        <AiCabinetSubnav />
        <Card class="p-4">
            <DataTable :columns="columns" :data="cabinets" />
        </Card>
    </AdminLayout>
</template>