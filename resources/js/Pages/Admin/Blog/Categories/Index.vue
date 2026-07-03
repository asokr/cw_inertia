<script setup>
import { Head, router, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import { actionsColumn, renderRowActions } from "@/lib/tableActions";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";

const props = defineProps({
    categories: { type: Array, default: () => [] },
});

const dialogOpen = ref(false);
const editing = ref(null);

const form = useForm({ name: "", slug: "" });

const columns = [
    { accessorKey: "name", header: "Название", cell: ({ row }) => row.original.name },
    { accessorKey: "slug", header: "Slug", cell: ({ row }) => row.original.slug },
    {
        ...actionsColumn,
        cell: ({ row }) => renderRowActions([
            { label: "Изменить", onClick: () => openEdit(row.original) },
            { label: "Удалить", variant: "destructive", onClick: () => destroyCategory(row.original) },
        ]),
    },
];

function openCreate() {
    editing.value = null;
    form.reset();
    dialogOpen.value = true;
}

function openEdit(category) {
    editing.value = category;
    form.name = category.name;
    form.slug = category.slug ?? "";
    dialogOpen.value = true;
}

function destroyCategory(category) {
    if (!confirm(`Удалить категорию «${category.name}»?`)) return;
    router.delete(`/cw-page/blog/categories/${category.id}`);
}

function submit() {
    if (editing.value) {
        form.put(`/cw-page/blog/categories/${editing.value.id}`, {
            onSuccess: () => { dialogOpen.value = false; },
        });
    } else {
        form.post("/cw-page/blog/categories", {
            onSuccess: () => { dialogOpen.value = false; form.reset(); },
        });
    }
}
</script>

<template>
    <Head title="Категории блога" />

    <AdminLayout title="Категории" :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Категории' }]">
        <PageHeader title="Категории блога" description="Управление категориями публикаций">
            <template #actions>
                <Button @click="openCreate">Добавить</Button>
            </template>
        </PageHeader>

        <Card class="p-4">
            <DataTable :columns="columns" :data="categories" />
        </Card>

        <Dialog v-model:open="dialogOpen" :title="editing ? 'Редактировать категорию' : 'Новая категория'">
            <div class="space-y-3">
                <Input v-model="form.name" placeholder="Название" />
                <Input v-model="form.slug" placeholder="Slug (необязательно)" />
            </div>
            <template #footer>
                <Button variant="outline" @click="dialogOpen = false">Отмена</Button>
                <Button :disabled="form.processing" @click="submit">Сохранить</Button>
            </template>
        </Dialog>
    </AdminLayout>
</template>