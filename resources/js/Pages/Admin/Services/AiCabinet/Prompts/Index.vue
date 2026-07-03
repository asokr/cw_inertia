<script setup>
import { Head, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import { actionsColumn, renderRowActions } from "@/lib/tableActions";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import AiCabinetSubnav from "@/components/admin/AiCabinetSubnav.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Textarea from "@/components/ui/Textarea.vue";
import Select from "@/components/ui/Select.vue";
import Switch from "@/components/ui/Switch.vue";
import Badge from "@/components/ui/Badge.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";

const props = defineProps({
    templates: { type: Array, default: () => [] },
    responseFormats: { type: Array, default: () => [] },
});

const dialogOpen = ref(false);
const deleteOpen = ref(false);
const editing = ref(null);
const deleteTarget = ref(null);

const form = useForm({
    name: "",
    description: "",
    system_prompt: "",
    sort_order: 100,
    is_active: true,
    response_format: "json",
});

const formatLabel = (value) => props.responseFormats.find((f) => f.value === value)?.label ?? value;

const columns = [
    { accessorKey: "name", header: "Название", cell: ({ row }) => row.original.name },
    {
        accessorKey: "description",
        header: "Описание",
        cell: ({ row }) => {
            const text = row.original.description ?? "";
            return text.length > 80 ? `${text.slice(0, 80)}…` : text || "—";
        },
    },
    { accessorKey: "sort_order", header: "Порядок", cell: ({ row }) => row.original.sort_order },
    {
        accessorKey: "is_active",
        header: "Активен",
        cell: ({ row }) => (row.original.is_active ? "Да" : "Нет"),
    },
    {
        accessorKey: "response_format",
        header: "Формат",
        cell: ({ row }) => formatLabel(row.original.response_format),
    },
    {
        ...actionsColumn,
        cell: ({ row }) => renderRowActions([
            { label: "Изменить", onClick: () => openEdit(row.original) },
            { label: "Удалить", variant: "destructive", onClick: () => confirmDelete(row.original) },
        ]),
    },
];

function openCreate() {
    editing.value = null;
    form.reset();
    form.sort_order = 100;
    form.is_active = true;
    form.response_format = "json";
    dialogOpen.value = true;
}

function openEdit(template) {
    editing.value = template;
    form.name = template.name ?? "";
    form.description = template.description ?? "";
    form.system_prompt = template.system_prompt ?? "";
    form.sort_order = template.sort_order ?? 100;
    form.is_active = !!template.is_active;
    form.response_format = template.response_format ?? "json";
    dialogOpen.value = true;
}

function confirmDelete(template) {
    deleteTarget.value = template;
    deleteOpen.value = true;
}

function submit() {
    if (editing.value) {
        form.put(`/cw-page/services/ai-cabinet/prompts/${editing.value.id}`, {
            onSuccess: () => { dialogOpen.value = false; },
        });
    } else {
        form.post("/cw-page/services/ai-cabinet/prompts", {
            onSuccess: () => { dialogOpen.value = false; form.reset(); },
        });
    }
}

function destroyTemplate() {
    if (!deleteTarget.value) return;
    form.delete(`/cw-page/services/ai-cabinet/prompts/${deleteTarget.value.id}`, {
        onSuccess: () => { deleteOpen.value = false; deleteTarget.value = null; },
    });
}
</script>

<template>
    <Head title="Промпты ИИ-анализа" />

    <AdminLayout
        title="Промпты ИИ-анализа"
        :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Промпты ИИ-анализа' }]"
    >
        <PageHeader
            title="Промпты для ИИ-анализа кабинета WB"
            description="Системные промпты, используемые при ИИ-анализе отчётов"
        >
            <template #actions>
                <Button @click="openCreate">Добавить промпт</Button>
            </template>
        </PageHeader>

        <AiCabinetSubnav />

        <Card class="p-4">
            <DataTable :columns="columns" :data="templates" />
        </Card>

        <Dialog v-model:open="dialogOpen" :title="editing ? 'Редактировать промпт' : 'Добавить промпт'">
            <div class="space-y-3">
                <div>
                    <label class="mb-1 block text-sm font-medium">Название</label>
                    <Input v-model="form.name" placeholder="Название промпта" :error="!!form.errors.name" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Описание</label>
                    <Textarea v-model="form.description" rows="2" placeholder="Краткое описание" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">System Prompt</label>
                    <Textarea
                        v-model="form.system_prompt"
                        rows="10"
                        class="font-mono text-xs"
                        placeholder="Ты senior-аналитик..."
                        :error="!!form.errors.system_prompt"
                    />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Порядок</label>
                        <Input v-model.number="form.sort_order" type="number" min="0" />
                    </div>
                    <div class="flex items-end gap-2 pb-1">
                        <Switch id="is_active" v-model="form.is_active" />
                        <label for="is_active" class="text-sm">Активен</label>
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Формат ответа</label>
                    <Select v-model="form.response_format">
                        <option v-for="fmt in responseFormats" :key="fmt.value" :value="fmt.value">
                            {{ fmt.label }}
                        </option>
                    </Select>
                </div>
            </div>
            <template #footer>
                <Button variant="outline" @click="dialogOpen = false">Отмена</Button>
                <Button :disabled="form.processing" @click="submit">
                    {{ editing ? "Сохранить" : "Создать" }}
                </Button>
            </template>
        </Dialog>

        <Dialog v-model:open="deleteOpen" title="Удалить промпт?">
            <p class="text-sm">
                Удалить промпт <Badge>{{ deleteTarget?.name }}</Badge>?
                Связанные анализы будут удалены каскадно.
            </p>
            <template #footer>
                <Button variant="outline" @click="deleteOpen = false">Отмена</Button>
                <Button variant="destructive" :disabled="form.processing" @click="destroyTemplate">Удалить</Button>
            </template>
        </Dialog>
    </AdminLayout>
</template>