<script setup>
import { Head, router } from "@inertiajs/vue3";
import { h, ref } from "vue";
import { actionsColumn, renderRowActions } from "@/lib/tableActions";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Select from "@/components/ui/Select.vue";
import Badge from "@/components/ui/Badge.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    posts: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const search = ref(props.filters.search ?? "");
const status = ref(props.filters.status ?? "");

const statusLabel = (value) => ({ draft: "Черновик", published: "Опубликован", hidden: "Скрыт" }[value] ?? value);

const columns = [
    { accessorKey: "title", header: "Заголовок", cell: ({ row }) => row.original.title },
    {
        accessorKey: "status",
        header: "Статус",
        cell: ({ row }) => h(Badge, { variant: row.original.status === "published" ? "success" : "secondary" }, () => statusLabel(row.original.status)),
    },
    { accessorKey: "views_count", header: "Просмотры", cell: ({ row }) => row.original.views_count ?? 0 },
    {
        accessorKey: "published_at",
        header: "Дата",
        cell: ({ row }) => row.original.published_at ? new Date(row.original.published_at).toLocaleDateString("ru-RU") : "—",
    },
    {
        ...actionsColumn,
        cell: ({ row }) => renderRowActions([
            { label: "Изменить", href: `/cw-page/blog/posts/${row.original.id}/edit` },
            { label: "Удалить", variant: "destructive", onClick: () => deletePost(row.original.id) },
        ]),
    },
];

function applyFilters() {
    router.get("/cw-page/blog/posts", {
        search: search.value || undefined,
        status: status.value || undefined,
        per_page: props.filters.per_page,
    }, { preserveState: true });
}

function deletePost(id) {
    if (!confirm("Удалить пост?")) return;
    router.delete(`/cw-page/blog/posts/${id}`);
}
</script>

<template>
    <Head title="Посты блога" />

    <AdminLayout title="Посты" :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Блог' }, { label: 'Посты' }]">
        <PageHeader title="Посты блога" description="Управление публикациями и статусами">
            <template #actions>
                <Button as="a" href="/cw-page/blog/posts/create">Создать пост</Button>
            </template>
        </PageHeader>

        <Card class="mb-4 p-4">
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm">Поиск</label>
                    <Input v-model="search" placeholder="Минимум 3 символа" @keyup.enter="applyFilters" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Статус</label>
                    <Select v-model="status">
                        <option :value="null">Все</option>
                        <option value="draft">Черновик</option>
                        <option value="published">Опубликован</option>
                        <option value="hidden">Скрыт</option>
                    </Select>
                </div>
                <div class="flex items-end gap-2">
                    <Button @click="applyFilters">Применить</Button>
                    <Button variant="outline" @click="search = ''; status = null; applyFilters()">Сбросить</Button>
                </div>
            </div>
        </Card>

        <DataTable :columns="columns" :data="posts.data ?? []" />

        <div v-if="posts.meta" class="mt-4 flex items-center justify-between text-sm text-muted-foreground">
            <span>Страница {{ posts.meta.current_page }} из {{ posts.meta.last_page }}</span>
            <div class="flex gap-2">
                <Button
                    v-if="posts.links?.prev"
                    variant="outline"
                    size="sm"
                    @click="router.get(posts.links.prev)"
                >
                    Назад
                </Button>
                <Button
                    v-if="posts.links?.next"
                    variant="outline"
                    size="sm"
                    @click="router.get(posts.links.next)"
                >
                    Вперёд
                </Button>
            </div>
        </div>
    </AdminLayout>
</template>