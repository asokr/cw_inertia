<script setup>
import { Head, router } from "@inertiajs/vue3";
import { h, ref } from "vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import FeedbacksSubnav from "@/components/admin/FeedbacksSubnav.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";

const props = defineProps({
    reviews: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const detailOpen = ref(false);
const detailTitle = ref("");
const detailText = ref("");

const formatDate = (value) => value ? new Date(value).toLocaleString("ru-RU") : "—";

function getAiResponse(review) {
    return review.bot_response?.response_text ?? review.botResponse?.response_text ?? "";
}

function openDetail(title, text) {
    if (!text) return;
    detailTitle.value = title;
    detailText.value = text;
    detailOpen.value = true;
}

function renderExpandableText(text, title, maxLength = 120) {
    if (!text) return "—";
    if (text.length <= maxLength) return text;

    return h(
        "button",
        {
            type: "button",
            class: "max-w-md text-left text-sm leading-snug text-foreground hover:text-primary hover:underline",
            onClick: () => openDetail(title, text),
        },
        `${text.slice(0, maxLength)}…`,
    );
}

const columns = [
    { accessorKey: "cabinet.name", header: "Кабинет", cell: ({ row }) => row.original.cabinet?.name ?? "—" },
    { accessorKey: "rating", header: "Оценка", cell: ({ row }) => "★".repeat(row.original.rating ?? 0) || "—" },
    {
        id: "product",
        header: "Товар",
        cell: ({ row }) => h("div", { class: "text-xs" }, [
            h("div", row.original.subject_name ?? "—"),
            h("div", { class: "text-muted-foreground" }, `Арт: ${row.original.product_id ?? "—"}`),
        ]),
    },
    {
        id: "content",
        header: "Отзыв",
        cell: ({ row }) => renderExpandableText(row.original.content ?? "", "Отзыв"),
    },
    {
        id: "response",
        header: "Ответ ИИ",
        cell: ({ row }) => renderExpandableText(getAiResponse(row.original), "Ответ ИИ"),
    },
    { accessorKey: "created_at", header: "Дата", cell: ({ row }) => formatDate(row.original.created_at) },
];
</script>

<template>
    <Head title="АвтоОтветы ИИ" />

    <AdminLayout title="АвтоОтветы" :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'АвтоОтветы ИИ' }]">
        <PageHeader title="АвтоОтветы ИИ" description="Отзывы с ответами бота / ИИ" />
        <FeedbacksSubnav />
        <Card class="p-4">
            <DataTable :columns="columns" :data="reviews.data ?? []" />
            <div v-if="reviews.last_page > 1" class="mt-4 flex justify-between text-sm text-muted-foreground">
                <span>Страница {{ reviews.current_page }} из {{ reviews.last_page }} ({{ reviews.total }})</span>
                <div class="flex gap-2">
                    <Button
                        v-if="reviews.current_page > 1"
                        variant="outline"
                        size="sm"
                        @click="router.get('/cw-page/services/feedbacks/ai-answers', { ...filters, page: reviews.current_page - 1 })"
                    >
                        Назад
                    </Button>
                    <Button
                        v-if="reviews.current_page < reviews.last_page"
                        variant="outline"
                        size="sm"
                        @click="router.get('/cw-page/services/feedbacks/ai-answers', { ...filters, page: reviews.current_page + 1 })"
                    >
                        Далее
                    </Button>
                </div>
            </div>
        </Card>

        <Dialog v-model:open="detailOpen" :title="detailTitle" class="max-w-2xl">
            <p class="max-h-[70vh] overflow-y-auto whitespace-pre-wrap break-words text-sm leading-relaxed">
                {{ detailText }}
            </p>
        </Dialog>
    </AdminLayout>
</template>