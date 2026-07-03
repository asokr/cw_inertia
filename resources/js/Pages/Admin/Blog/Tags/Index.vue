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

defineProps({
    tags: { type: Array, default: () => [] },
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
            { label: "Удалить", variant: "destructive", onClick: () => destroyTag(row.original) },
        ]),
    },
];

function openCreate() {
    editing.value = null;
    form.reset();
    dialogOpen.value = true;
}

function openEdit(tag) {
    editing.value = tag;
    form.name = tag.name;
    form.slug = tag.slug ?? "";
    dialogOpen.value = true;
}

function destroyTag(tag) {
    if (!confirm(`Удалить тег «${tag.name}»?`)) return;
    router.delete(`/cw-page/blog/tags/${tag.id}`);
}

function submit() {
    if (editing.value) {
        form.put(`/cw-page/blog/tags/${editing.value.id}`, {
            onSuccess: () => { dialogOpen.value = false; },
        });
    } else {
        form.post("/cw-page/blog/tags", {
            onSuccess: () => { dialogOpen.value = false; form.reset(); },
        });
    }
}
</script>

<template>
    <Head title="Теги блога" />

    <AdminLayout title="Теги" :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Теги' }]">
        <PageHeader title="Теги блога" description="Управление тегами публикаций">
            <template #actions>
                <Button @click="openCreate">Добавить</Button>
            </template>
        </PageHeader>

        <Card class="p-4">
            <DataTable :columns="columns" :data="tags" />
        </Card>

        <Dialog v-model:open="dialogOpen" :title="editing ? 'Редактировать тег' : 'Новый тег'">
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