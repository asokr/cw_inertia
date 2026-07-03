<script setup>
import { Head, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import { actionsColumn, renderRowActions } from "@/lib/tableActions";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import ManagementSubnav from "@/components/admin/ManagementSubnav.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Select from "@/components/ui/Select.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";

const props = defineProps({
    coupons: { type: Array, default: () => [] },
    couponTypes: { type: Array, default: () => [] },
});

const dialogOpen = ref(false);
const editing = ref(null);

const form = useForm({
    code: "",
    limit: null,
    type: "fixed",
    value: 0,
    start_date: "",
    end_date: "",
});

const typeLabel = (type) => props.couponTypes.find((t) => t.value === type)?.label ?? type;

const formatDate = (value) => {
    if (!value) return "—";
    return new Date(value).toLocaleString("ru-RU");
};

const columns = [
    { accessorKey: "code", header: "Код", cell: ({ row }) => row.original.code },
    { accessorKey: "limit", header: "Лимит", cell: ({ row }) => row.original.limit ?? "Без лимита" },
    { accessorKey: "type", header: "Тип", cell: ({ row }) => typeLabel(row.original.type) },
    { accessorKey: "value", header: "Значение", cell: ({ row }) => row.original.value },
    { accessorKey: "start_date", header: "С", cell: ({ row }) => formatDate(row.original.start_date) },
    { accessorKey: "end_date", header: "До", cell: ({ row }) => formatDate(row.original.end_date) },
    {
        ...actionsColumn,
        cell: ({ row }) => renderRowActions([
            { label: "Изменить", onClick: () => openEdit(row.original) },
            { label: "Удалить", variant: "destructive", onClick: () => destroyCoupon(row.original) },
        ]),
    },
];

function openCreate() {
    editing.value = null;
    form.reset();
    form.type = "fixed";
    dialogOpen.value = true;
}

function openEdit(coupon) {
    editing.value = coupon;
    form.code = coupon.code;
    form.limit = coupon.limit;
    form.type = coupon.type;
    form.value = coupon.value;
    form.start_date = coupon.start_date ? coupon.start_date.slice(0, 16) : "";
    form.end_date = coupon.end_date ? coupon.end_date.slice(0, 16) : "";
    dialogOpen.value = true;
}

function submit() {
    const payload = { ...form.data() };
    if (!payload.limit) payload.limit = null;

    if (editing.value) {
        form.transform(() => payload).put(`/cw-page/coupons/${editing.value.id}`, {
            onSuccess: () => { dialogOpen.value = false; },
        });
    } else {
        form.transform(() => payload).post("/cw-page/coupons", {
            onSuccess: () => { dialogOpen.value = false; form.reset(); },
        });
    }
}

function destroyCoupon(coupon) {
    if (!confirm(`Удалить купон «${coupon.code}»?`)) return;
    form.delete(`/cw-page/coupons/${coupon.id}`);
}
</script>

<template>
    <Head title="Купоны" />

    <AdminLayout title="Купоны" :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Купоны' }]">
        <PageHeader title="Купоны и промокоды" description="Управление скидками и регистрационными кодами">
            <template #actions>
                <Button @click="openCreate">Добавить</Button>
            </template>
        </PageHeader>

        <ManagementSubnav />

        <Card class="p-4">
            <DataTable :columns="columns" :data="coupons" />
        </Card>

        <Dialog v-model:open="dialogOpen" :title="editing ? 'Редактировать купон' : 'Новый купон'">
            <div class="space-y-3">
                <Input v-model="form.code" placeholder="Код купона" />
                <Input v-model.number="form.limit" type="number" min="0" placeholder="Лимит использования (необязательно)" />
                <Select v-model="form.type">
                    <option v-for="t in couponTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                </Select>
                <Input v-model.number="form.value" type="number" step="0.01" :placeholder="form.type === 'registration' ? 'ID тарифа' : 'Значение скидки'" />
                <div class="grid gap-3 sm:grid-cols-2">
                    <Input v-model="form.start_date" type="datetime-local" />
                    <Input v-model="form.end_date" type="datetime-local" />
                </div>
            </div>
            <template #footer>
                <Button variant="outline" @click="dialogOpen = false">Отмена</Button>
                <Button :disabled="form.processing" @click="submit">Сохранить</Button>
            </template>
        </Dialog>
    </AdminLayout>
</template>