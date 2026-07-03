<script setup>
import { Head, router } from "@inertiajs/vue3";
import { ref } from "vue";
import { actionsColumn, renderRowActions } from "@/lib/tableActions";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Select from "@/components/ui/Select.vue";
import Badge from "@/components/ui/Badge.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";

const props = defineProps({
    logs: { type: Object, required: true },
    taskTypes: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const detailOpen = ref(false);
const detailText = ref("");
const imagePreview = ref({ open: false, src: "" });

const localFilters = ref({ ...props.filters });

const columns = [
    { accessorKey: "created_at", header: "Дата", cell: ({ row }) => row.original.created_at },
    { accessorKey: "task_type", header: "Задача", cell: ({ row }) => row.original.task_type },
    { accessorKey: "provider", header: "Provider", cell: ({ row }) => (row.original.provider || "—").toUpperCase() },
    { accessorKey: "marketplace", header: "MP", cell: ({ row }) => (row.original.marketplace || "—").toUpperCase() },
    {
        accessorKey: "status_code",
        header: "Status",
        cell: ({ row }) => row.original.status_code ?? "—",
    },
    { accessorKey: "user_id", header: "User", cell: ({ row }) => row.original.user_id ?? "—" },
    { accessorKey: "subscriber_id", header: "Sub", cell: ({ row }) => row.original.subscriber_id ?? "—" },
    { accessorKey: "total_tokens", header: "Tokens", cell: ({ row }) => row.original.total_tokens ?? "—" },
    {
        id: "response",
        header: "Ответ",
        cell: ({ row }) => row.original.response_text_preview || "—",
    },
    {
        ...actionsColumn,
        cell: ({ row }) => {
            const log = row.original;
            const actions = [];

            if (log.response_text_preview) {
                actions.push({
                    label: "Ответ ИИ",
                    onClick: () => showDetail(log.response_text_full || log.response_text_preview),
                });
            }
            if (log.error_message) {
                actions.push({
                    label: "Ошибка",
                    variant: "destructive",
                    onClick: () => showDetail(log.error_message),
                });
            }
            if (log.request_payload_preview) {
                actions.push({
                    label: "Payload",
                    onClick: () => showDetail(log.request_payload_full || log.request_payload_preview),
                });
            }
            if (log.provider_request_payload_preview) {
                actions.push({
                    label: "Provider req",
                    onClick: () => showDetail(log.provider_request_payload_full || log.provider_request_payload_preview),
                });
            }
            if (Array.isArray(log.response_images) && log.response_images.length) {
                actions.push({
                    label: log.response_images.length > 1 ? `Фото (${log.response_images.length})` : "Фото",
                    onClick: () => {
                        imagePreview.value = {
                            open: true,
                            src: log.response_images[0].url || log.response_images[0].data_uri,
                        };
                    },
                });
            }
            if (Array.isArray(log.response_videos) && log.response_videos.length) {
                log.response_videos.forEach((video, idx) => {
                    if (video.url) {
                        actions.push({
                            label: log.response_videos.length > 1 ? `Видео ${idx + 1}` : "Видео",
                            href: video.url,
                            target: "_blank",
                            rel: "noopener noreferrer",
                        });
                    }
                });
            }

            return renderRowActions(actions);
        },
    },
];

function showDetail(text) {
    if (!text) return;
    detailText.value = text;
    detailOpen.value = true;
}

function applyFilters() {
    router.get("/cw-page/services/ai/marketplace-logs", {
        ...localFilters.value,
        page: 1,
    }, { preserveState: true });
}

function applyPreset(taskType) {
    localFilters.value.task_type = taskType;
    applyFilters();
}

function resetFilters() {
    localFilters.value = {
        date_from: "",
        date_to: "",
        task_type: "",
        status_code: "",
        search: "",
        per_page: localFilters.value.per_page || 25,
    };
    applyFilters();
}

function changePage(page) {
    router.get("/cw-page/services/ai/marketplace-logs", {
        ...localFilters.value,
        page,
    }, { preserveState: true });
}
</script>

<template>
    <Head title="Логи ИИ-запросов" />

    <AdminLayout
        title="Логи ИИ-запросов"
        :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Логи ИИ-запросов' }]"
    >
        <PageHeader
            title="Логи ИИ-запросов"
            description="Мониторинг запросов к AI-провайдерам: статусы, токены, ошибки и входные данные"
        />

        <Card class="mb-4 p-4">
            <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-5">
                <div>
                    <label class="mb-1 block text-xs font-medium">Дата от</label>
                    <Input v-model="localFilters.date_from" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Дата до</label>
                    <Input v-model="localFilters.date_to" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Тип задачи</label>
                    <Select v-model="localFilters.task_type">
                        <option value="">Все</option>
                        <option v-for="t in taskTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                    </Select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Статус код</label>
                    <Input v-model="localFilters.status_code" placeholder="200 / 429" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Поиск (user/sub)</label>
                    <Input v-model="localFilters.search" placeholder="ID..." />
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="text-xs text-muted-foreground">Пресеты:</span>
                <Button size="sm" variant="outline" @click="applyPreset('wb_feedback_answer_ai')">WB AI</Button>
                <Button size="sm" variant="outline" @click="applyPreset('wb_feedback_answer_template')">WB шаблон</Button>
                <Button size="sm" variant="outline" @click="applyPreset('ozon_feedback_answer_ai')">Ozon AI</Button>
                <Button size="sm" variant="outline" @click="applyPreset('generate_video')">Видео</Button>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t pt-3">
                <Badge variant="secondary">{{ logs.total }} записей</Badge>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" @click="resetFilters">Сбросить</Button>
                    <Button size="sm" @click="applyFilters">Применить</Button>
                </div>
            </div>
        </Card>

        <Card class="p-4">
            <DataTable :columns="columns" :data="logs.data ?? []" />

            <div v-if="logs.last_page > 1" class="mt-4 flex justify-between text-sm text-muted-foreground">
                <span>Страница {{ logs.current_page }} из {{ logs.last_page }}</span>
                <div class="flex gap-2">
                    <Button
                        v-if="logs.current_page > 1"
                        variant="outline"
                        size="sm"
                        @click="changePage(logs.current_page - 1)"
                    >
                        Назад
                    </Button>
                    <Button
                        v-if="logs.current_page < logs.last_page"
                        variant="outline"
                        size="sm"
                        @click="changePage(logs.current_page + 1)"
                    >
                        Далее
                    </Button>
                </div>
            </div>
        </Card>

        <Dialog v-model:open="detailOpen" title="Детали">
            <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words text-xs">{{ detailText }}</pre>
        </Dialog>

        <Dialog v-model:open="imagePreview.open" title="Просмотр изображения">
            <img v-if="imagePreview.src" :src="imagePreview.src" alt="Preview" class="max-h-[70vh] w-full rounded object-contain" />
        </Dialog>
    </AdminLayout>
</template>