<script setup>
import { Head, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import { actionsColumn, renderRowActions } from "@/lib/tableActions";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import SubscribersSubnav from "@/components/admin/SubscribersSubnav.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";

const props = defineProps({
    extraLimits: { type: Array, default: () => [] },
});

const dialogOpen = ref(false);
const editing = ref(null);

const form = useForm({
    limit_name: "",
    quantity: 0,
    price: 0,
    order: 0,
});

const formatCurrency = (value) => new Intl.NumberFormat("ru-RU", {
    style: "currency",
    currency: "RUB",
}).format(Number(value ?? 0));

const columns = [
    { accessorKey: "limit_name", header: "Лимит", cell: ({ row }) => row.original.limit_name },
    { accessorKey: "quantity", header: "Кол-во", cell: ({ row }) => row.original.quantity },
    { accessorKey: "price", header: "Стоимость", cell: ({ row }) => formatCurrency(row.original.price) },
    { accessorKey: "order", header: "Порядок", cell: ({ row }) => row.original.order },
    {
        ...actionsColumn,
        cell: ({ row }) => renderRowActions([
            { label: "Изменить", onClick: () => openEdit(row.original) },
            { label: "Удалить", variant: "destructive", onClick: () => destroyItem(row.original) },
        ]),
    },
];

function openCreate() {
    editing.value = null;
    form.reset();
    dialogOpen.value = true;
}

function openEdit(item) {
    editing.value = item;
    form.limit_name = item.limit_name;
    form.quantity = item.quantity;
    form.price = item.price;
    form.order = item.order ?? 0;
    dialogOpen.value = true;
}

function submit() {
    if (editing.value) {
        form.put(`/cw-page/extra-limits/${editing.value.id}`, {
            onSuccess: () => { dialogOpen.value = false; },
        });
    } else {
        form.post("/cw-page/extra-limits", {
            onSuccess: () => { dialogOpen.value = false; form.reset(); },
        });
    }
}

function destroyItem(item) {
    if (!confirm(`Удалить лимит «${item.limit_name}»?`)) return;
    form.delete(`/cw-page/extra-limits/${item.id}`);
}
</script>

<template>
    <Head title="Экстра-лимиты" />

    <AdminLayout title="Экстра-лимиты" :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Экстра-лимиты' }]">
        <PageHeader title="Дополнительные лимиты" description="Пакеты дополнительных лимитов для подписчиков">
            <template #actions>
                <Button @click="openCreate">Добавить</Button>
            </template>
        </PageHeader>

        <SubscribersSubnav />

        <Card class="p-4">
            <DataTable :columns="columns" :data="extraLimits" />
        </Card>

        <Dialog v-model:open="dialogOpen" :title="editing ? 'Редактировать лимит' : 'Новый лимит'">
            <div class="space-y-3">
                <Input v-model="form.limit_name" placeholder="Название лимита" />
                <Input v-model.number="form.quantity" type="number" min="0" placeholder="Количество" />
                <Input v-model.number="form.price" type="number" min="0" step="0.01" placeholder="Стоимость" />
                <Input v-model.number="form.order" type="number" min="0" placeholder="Порядок" />
            </div>
            <template #footer>
                <Button variant="outline" @click="dialogOpen = false">Отмена</Button>
                <Button :disabled="form.processing" @click="submit">Сохранить</Button>
            </template>
        </Dialog>
    </AdminLayout>
</template>