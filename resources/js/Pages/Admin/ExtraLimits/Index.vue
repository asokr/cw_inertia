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
import Label from "@/components/ui/Label.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";

const props = defineProps({
    extraLimits: { type: Array, default: () => [] },
});

const dialogOpen = ref(false);
const editing = ref(null);
const editingId = ref(null);

const form = useForm({
    slug: "",
    name: "",
    price: 0,
    order: 0,
});

const formatCurrency = (value) =>
    new Intl.NumberFormat("ru-RU", {
        style: "currency",
        currency: "RUB",
        minimumFractionDigits: 0,
        maximumFractionDigits: 4,
    }).format(Number(value ?? 0));

const columns = [
    { accessorKey: "slug", header: "Slug", cell: ({ row }) => row.original.slug },
    { accessorKey: "name", header: "Название", cell: ({ row }) => row.original.name },
    {
        accessorKey: "price",
        header: "₽ за 1",
        cell: ({ row }) => formatCurrency(row.original.price),
    },
    { accessorKey: "order", header: "Порядок", cell: ({ row }) => row.original.order },
    {
        ...actionsColumn,
        cell: ({ row }) =>
            renderRowActions([
                { label: "Изменить", onClick: () => openEdit(row.original) },
                { label: "Удалить", variant: "destructive", onClick: () => destroyItem(row.original) },
            ]),
    },
];

function openCreate() {
    editing.value = null;
    editingId.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(item) {
    if (item?.id == null) {
        console.error("Extra limit row has no id", item);
        return;
    }
    editing.value = item;
    editingId.value = item.id;
    form.slug = item.slug ?? "";
    form.name = item.name ?? "";
    form.price = Number(item.price ?? 0);
    form.order = Number(item.order ?? 0);
    form.clearErrors();
    dialogOpen.value = true;
}

function submit() {
    if (editingId.value != null) {
        form.put(`/cw-page/extra-limits/${editingId.value}`, {
            preserveScroll: true,
            onSuccess: () => {
                dialogOpen.value = false;
                editing.value = null;
                editingId.value = null;
            },
        });
        return;
    }

    form.post("/cw-page/extra-limits", {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset();
        },
    });
}

function destroyItem(item) {
    if (item?.id == null) return;
    if (!confirm(`Удалить лимит «${item.name}» (${item.slug})?`)) return;
    form.delete(`/cw-page/extra-limits/${item.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Экстра-лимиты" />

    <AdminLayout
        title="Экстра-лимиты"
        :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Экстра-лимиты' }]"
    >
        <PageHeader
            title="Дополнительные лимиты"
            description="Цена за одну единицу; название показывается подписчику в магазине"
        >
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
                <div class="space-y-1">
                    <Label for="slug">Slug</Label>
                    <Input
                        id="slug"
                        v-model="form.slug"
                        placeholder="ai_text_query"
                        :class="form.errors.slug ? 'border-destructive' : ''"
                    />
                    <p v-if="form.errors.slug" class="text-xs text-destructive">{{ form.errors.slug }}</p>
                </div>
                <div class="space-y-1">
                    <Label for="name">Название (RU)</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        placeholder="Текстовые запросы к ИИ"
                        :class="form.errors.name ? 'border-destructive' : ''"
                    />
                    <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                </div>
                <div class="space-y-1">
                    <Label for="price">Стоимость за 1 лимит, ₽</Label>
                    <Input
                        id="price"
                        v-model.number="form.price"
                        type="number"
                        min="0"
                        step="0.01"
                        :class="form.errors.price ? 'border-destructive' : ''"
                    />
                    <p v-if="form.errors.price" class="text-xs text-destructive">{{ form.errors.price }}</p>
                </div>
                <div class="space-y-1">
                    <Label for="order">Порядок</Label>
                    <Input id="order" v-model.number="form.order" type="number" min="0" />
                </div>
            </div>
            <template #footer>
                <Button variant="outline" @click="dialogOpen = false">Отмена</Button>
                <Button :disabled="form.processing" @click="submit">Сохранить</Button>
            </template>
        </Dialog>
    </AdminLayout>
</template>
