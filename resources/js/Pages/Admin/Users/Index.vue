<script setup>
import { Head, router } from "@inertiajs/vue3";
import { h, ref } from "vue";
import { actionsColumn, renderRowActions } from "@/lib/tableActions";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import ManagementSubnav from "@/components/admin/ManagementSubnav.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Select from "@/components/ui/Select.vue";
import Badge from "@/components/ui/Badge.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    users: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const search = ref(props.filters.search ?? "");
const role = ref(props.filters.role ?? "");

const columns = [
    {
        accessorKey: "full_name",
        header: "Пользователь",
        cell: ({ row }) => h("div", { class: "text-sm" }, [
            h("div", { class: "font-medium" }, row.original.full_name || row.original.name),
            h("div", { class: "text-muted-foreground" }, row.original.email),
        ]),
    },
    {
        id: "roles",
        header: "Роли",
        cell: ({ row }) => {
            const roles = row.original.roles ?? [];
            if (!roles.length) return "—";
            return h("div", { class: "flex flex-wrap gap-1" }, roles.map((r) =>
                h(Badge, { variant: "secondary", key: r.id }, () => r.name)
            ));
        },
    },
    {
        ...actionsColumn,
        cell: ({ row }) => renderRowActions([
            { label: "Изменить", href: `/cw-page/users/${row.original.id}/edit` },
        ]),
    },
];

function applyFilters() {
    router.get("/cw-page/users", {
        role: role.value || undefined,
        search: search.value?.length >= 2 ? search.value : undefined,
    }, { preserveState: true });
}
</script>

<template>
    <Head title="Пользователи" />

    <AdminLayout title="Пользователи" :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Пользователи' }]">
        <PageHeader title="Пользователи" description="Список пользователей системы" />

        <ManagementSubnav />

        <Card class="mb-4 p-4">
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm">Поиск</label>
                    <Input v-model="search" placeholder="Имя, email" @keyup.enter="applyFilters" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Роль</label>
                    <Select v-model="role">
                        <option value="">Все</option>
                        <option v-for="r in roles" :key="r.id" :value="r.name">{{ r.name }}</option>
                    </Select>
                </div>
                <div class="flex items-end">
                    <Button @click="applyFilters">Применить</Button>
                </div>
            </div>
        </Card>

        <Card class="p-4">
            <DataTable :columns="columns" :data="users" />
        </Card>
    </AdminLayout>
</template>