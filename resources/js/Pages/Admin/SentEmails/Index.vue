<script setup>
import { Head, Link, router } from "@inertiajs/vue3";
import { h, ref } from "vue";
import { actionsColumn, renderRowActions } from "@/lib/tableActions";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import ManagementSubnav from "@/components/admin/ManagementSubnav.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    emails: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const search = ref(props.filters.search ?? "");

const columns = [
    { accessorKey: "id", header: "ID", cell: ({ row }) => row.original.id },
    { accessorKey: "subject", header: "Тема", cell: ({ row }) => row.original.subject },
    { accessorKey: "to", header: "Кому", cell: ({ row }) => row.original.to ?? "—" },
    {
        id: "recipient",
        header: "Получатель",
        cell: ({ row }) => {
            const name = row.original.recipient_name ?? "—";
            const subId = row.original.subscriber_id;
            if (subId) {
                return h(Link, { href: `/cw-page/subscribers/${subId}`, class: "text-primary hover:underline text-sm" }, () => name);
            }
            return name;
        },
    },
    { accessorKey: "type", header: "Тип", cell: ({ row }) => row.original.type ?? "—" },
    { accessorKey: "status", header: "Статус", cell: ({ row }) => row.original.status ?? "—" },
    { accessorKey: "created_at", header: "Дата", cell: ({ row }) => row.original.created_at },
    {
        ...actionsColumn,
        cell: ({ row }) => renderRowActions([
            { label: "Открыть", href: `/cw-page/sent-emails/${row.original.id}` },
        ]),
    },
];

function applyFilters() {
    router.get("/cw-page/sent-emails", {
        search: search.value || undefined,
        per_page: props.filters.per_page,
        sort: props.filters.sort,
        order: props.filters.order,
    }, { preserveState: true });
}

function resetFilters() {
    search.value = "";
    router.get("/cw-page/sent-emails");
}
</script>

<template>
    <Head title="Отправленные письма" />

    <AdminLayout title="Письма" :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Письма' }]">
        <PageHeader title="Отправленные письма" :description="`Всего: ${emails.total ?? 0}`" />

        <ManagementSubnav />

        <Card class="mb-4 p-4">
            <div class="flex flex-wrap gap-2">
                <Input v-model="search" placeholder="Поиск по теме…" class="max-w-sm" @keyup.enter="applyFilters" />
                <Button @click="applyFilters">Найти</Button>
                <Button variant="outline" @click="resetFilters">Сбросить</Button>
            </div>
        </Card>

        <Card class="p-4">
            <DataTable :columns="columns" :data="emails.data ?? []" />
            <div v-if="emails.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                <span>Страница {{ emails.current_page }} из {{ emails.last_page }}</span>
                <div class="flex gap-2">
                    <Button
                        v-if="emails.current_page > 1"
                        variant="outline"
                        size="sm"
                        @click="router.get('/cw-page/sent-emails', { ...filters, page: emails.current_page - 1 })"
                    >
                        Назад
                    </Button>
                    <Button
                        v-if="emails.current_page < emails.last_page"
                        variant="outline"
                        size="sm"
                        @click="router.get('/cw-page/sent-emails', { ...filters, page: emails.current_page + 1 })"
                    >
                        Далее
                    </Button>
                </div>
            </div>
        </Card>
    </AdminLayout>
</template>