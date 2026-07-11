<script setup>
import { ref } from "vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import { Pencil, Trash2 } from "lucide-vue-next";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Switch from "@/components/ui/Switch.vue";
import Textarea from "@/components/ui/Textarea.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    client: { type: Object, required: true },
    templates: { type: Array, default: () => [] },
    templatesError: { type: String, default: null },
    botStatus: { type: Number, default: 0 },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Управление отзывами", href: "/panel/wb/feedbacks" },
    { label: props.client.name, href: `/panel/wb/feedbacks/clients/${props.client.id}` },
    { label: "Шаблоны" },
];

const addOpen = ref(false);
const editOpen = ref(false);
const deleteOpen = ref(false);
const selectedTemplate = ref(null);
const botEnabled = ref(Boolean(props.botStatus));
const botSaving = ref(false);

const addForm = useForm({
    text: "",
    minRating: 4,
    maxRating: 5,
});

const editForm = useForm({
    text: "",
    minRating: 1,
    maxRating: 5,
});

const baseUrl = `/panel/wb/feedbacks/clients/${props.client.id}`;
const { showError, watchPropToast } = useFlashToast();
watchPropToast(() => props.templatesError);
const templatesUrl = `${baseUrl}/templates`;

function openAdd() {
    addForm.reset();
    addForm.minRating = 4;
    addForm.maxRating = 5;
    addOpen.value = true;
}

function openEdit(template) {
    selectedTemplate.value = template;
    editForm.text = template.text;
    editForm.minRating = template.minRating;
    editForm.maxRating = template.maxRating;
    editOpen.value = true;
}

function openDelete(template) {
    selectedTemplate.value = template;
    deleteOpen.value = true;
}

function submitAdd() {
    addForm.post(templatesUrl, {
        preserveScroll: true,
        onSuccess: () => {
            addOpen.value = false;
            addForm.reset();
        },
        onError: () => {
            addOpen.value = true;
        },
    });
}

function submitEdit() {
    editForm.put(`${templatesUrl}/${selectedTemplate.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
        },
        onError: () => {
            editOpen.value = true;
        },
    });
}

function confirmDelete() {
    router.delete(`${templatesUrl}/${selectedTemplate.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
        },
    });
}

function updateBotStatus(value) {
    botEnabled.value = value;
    botSaving.value = true;
    router.post(
        `${baseUrl}/bot-status`,
        { bot_status: value ? 1 : 0 },
        {
            preserveScroll: true,
            onFinish: () => {
                botSaving.value = false;
            },
        },
    );
}

async function generateTemplateText() {
    try {
        const response = await fetch(`${baseUrl}/ai/generate`, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.content ?? "",
            },
            body: JSON.stringify({
                prompt: `Помоги составить шаблонный ответ на отзыв на товар в зависимости от оценки. Предлагай только один вариант шаблона. Покупатель поставил оценку от ${addForm.minRating} до ${addForm.maxRating} по пятибальной системе. Используй общие фразы, чтобы твой ответ подходил под различные товары. Максимальная длина текста - 300 символов.`,
                type: "копирайтер, задача которого отвечать на отзывы покупателей маркетплейса",
            }),
            credentials: "same-origin",
        });
        const data = await response.json();
        if (data.success) {
            addForm.text = data.data ?? "";
        }
    } catch {
        showError("Не удалось сгенерировать шаблон");
    }
}
</script>

<template>
    <Head :title="`Шаблоны — ${client.name}`" />

    <SubscriberLayout title="Шаблоны для автоответов" :breadcrumbs="breadcrumbs">
        <ToolPageHeader
            title="Шаблоны для автоответов на отзывы"
            description="Бот будет случайно выбирать один из шаблонов с учётом диапазона оценок."
        >
            <template #actions>
                <Button @click="openAdd">Добавить шаблон</Button>
            </template>
        </ToolPageHeader>

        <div v-if="templates.length" class="mb-6 flex items-center gap-3">
            <Switch :model-value="botEnabled" :disabled="botSaving" @update:model-value="updateBotStatus" />
            <span class="text-sm">Автоответчик работает: {{ botEnabled ? "Да" : "Нет" }}</span>
        </div>

        <div v-if="templates.length" class="space-y-3">
            <Card v-for="template in templates" :key="template.id" class="p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-2">
                        <p class="text-sm font-medium">
                            Оценки: {{ template.minRating }}–{{ template.maxRating }}
                        </p>
                        <p class="whitespace-pre-wrap text-sm text-muted-foreground">{{ template.text }}</p>
                    </div>
                    <div class="flex gap-2">
                        <Button variant="outline" size="sm" @click="openEdit(template)">
                            <Pencil class="h-3.5 w-3.5" />
                        </Button>
                        <Button variant="outline" size="sm" @click="openDelete(template)">
                            <Trash2 class="h-3.5 w-3.5" />
                        </Button>
                    </div>
                </div>
            </Card>
        </div>
        <p v-else class="text-sm text-muted-foreground">Шаблоны не добавлены.</p>

        <Dialog :open="addOpen" title="Добавление шаблона" @update:open="addOpen = $event">
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <label class="space-y-1 text-sm">
                        Мин. оценка
                        <input v-model.number="addForm.minRating" type="number" min="1" max="5" class="w-full rounded-md border px-3 py-2" />
                    </label>
                    <label class="space-y-1 text-sm">
                        Макс. оценка
                        <input v-model.number="addForm.maxRating" type="number" min="1" max="5" class="w-full rounded-md border px-3 py-2" />
                    </label>
                </div>
                <Button variant="outline" size="sm" @click="generateTemplateText">Сгенерировать ИИ</Button>
                <Textarea v-model="addForm.text" :rows="10" placeholder="Текст шаблона" />
                <p v-if="addForm.errors.text" class="text-sm text-destructive">{{ addForm.errors.text }}</p>
            </div>
            <template #footer>
                <Button variant="outline" @click="addOpen = false">Отмена</Button>
                <Button :disabled="addForm.processing" @click="submitAdd">Добавить</Button>
            </template>
        </Dialog>

        <Dialog :open="editOpen" title="Редактирование шаблона" @update:open="editOpen = $event">
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <label class="space-y-1 text-sm">
                        Мин. оценка
                        <input v-model.number="editForm.minRating" type="number" min="1" max="5" class="w-full rounded-md border px-3 py-2" />
                    </label>
                    <label class="space-y-1 text-sm">
                        Макс. оценка
                        <input v-model.number="editForm.maxRating" type="number" min="1" max="5" class="w-full rounded-md border px-3 py-2" />
                    </label>
                </div>
                <Textarea v-model="editForm.text" :rows="10" />
                <p v-if="editForm.errors.text" class="text-sm text-destructive">{{ editForm.errors.text }}</p>
            </div>
            <template #footer>
                <Button variant="outline" @click="editOpen = false">Отмена</Button>
                <Button :disabled="editForm.processing" @click="submitEdit">Сохранить</Button>
            </template>
        </Dialog>

        <Dialog :open="deleteOpen" title="Удаление шаблона" @update:open="deleteOpen = $event">
            <p class="text-sm">Удалить выбранный шаблон?</p>
            <template #footer>
                <Button variant="outline" @click="deleteOpen = false">Отмена</Button>
                <Button variant="destructive" @click="confirmDelete">Удалить</Button>
            </template>
        </Dialog>
    </SubscriberLayout>
</template>