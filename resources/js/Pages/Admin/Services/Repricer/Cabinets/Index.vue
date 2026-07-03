<script setup>
import { Head, Link, router } from "@inertiajs/vue3";
import { h } from "vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import RepricerSubnav from "@/components/admin/RepricerSubnav.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    cabinets: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const columns = [
    {
        id: "user",
        header: "Подписчик",
        cell: ({ row }) => {
            const user = row.original.user;
            const subId = user?.subscriber?.id;
            const label = user ? `${user.name} (${user.email})` : "—";
            if (subId) {
                return h(Link, { href: `/cw-page/subscribers/${subId}`, class: "text-primary hover:underline text-sm" }, () => label);
            }
            return label;
        },
    },
    { accessorKey: "name", header: "Кабинет", cell: ({ row }) => row.original.name },
    { accessorKey: "created_at", header: "Создан", cell: ({ row }) => row.original.created_at },
];
</script>

<template>
    <Head title="Репрайсер — кабинеты" />

    <AdminLayout title="Репрайсер" :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Репрайсер — кабинеты' }]">
        <PageHeader title="Кабинеты репрайсера" description="WB-кабинеты с подключённым репрайсером" />
        <RepricerSubnav />
        <Card class="p-4">
            <DataTable :columns="columns" :data="cabinets.data ?? []" />
            <div v-if="cabinets.last_page > 1" class="mt-4 flex justify-between text-sm text-muted-foreground">
                <span>Страница {{ cabinets.current_page }} из {{ cabinets.last_page }}</span>
                <div class="flex gap-2">
                    <Button
                        v-if="cabinets.current_page > 1"
                        variant="outline"
                        size="sm"
                        @click="router.get('/cw-page/services/repricer/cabinets', { ...filters, page: cabinets.current_page - 1 })"
                    >
                        Назад
                    </Button>
                    <Button
                        v-if="cabinets.current_page < cabinets.last_page"
                        variant="outline"
                        size="sm"
                        @click="router.get('/cw-page/services/repricer/cabinets', { ...filters, page: cabinets.current_page + 1 })"
                    >
                        Далее
                    </Button>
                </div>
            </div>
        </Card>
    </AdminLayout>
</template>