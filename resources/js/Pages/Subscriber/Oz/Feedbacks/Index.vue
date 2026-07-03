<script setup>
import { ref } from "vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import { Pencil, Trash2 } from "lucide-vue-next";
import CabinetForm from "@/components/subscriber/tools/CabinetForm.vue";
import TestingBanner from "@/components/subscriber/oz/feedbacks/TestingBanner.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Checkbox from "@/components/ui/Checkbox.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Label from "@/components/ui/Label.vue";
import Textarea from "@/components/ui/Textarea.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";

const props = defineProps({
    cabinets: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({}) },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Управление отзывами" },
];

const addOpen = ref(false);
const editOpen = ref(false);
const deleteOpen = ref(false);
const selectedCabinet = ref(null);

const addForm = useForm({
    name: "",
    apikey: "",
    client_id: "",
});

const editForm = useForm({
    name: "",
    apikey: "",
    empty_answer: false,
    signature: "",
});

function openAdd() {
    addForm.reset();
    addOpen.value = true;
}

function openEdit(cabinet) {
    selectedCabinet.value = cabinet;
    editForm.name = cabinet.name;
    editForm.apikey = cabinet.apikey ?? "";
    editForm.empty_answer = Boolean(cabinet.empty_answer);
    editForm.signature = cabinet.signature ?? "";
    editOpen.value = true;
}

function openDelete(cabinet) {
    selectedCabinet.value = cabinet;
    deleteOpen.value = true;
}

function submitAdd() {
    addForm.post("/panel/oz/feedbacks/cabinets", {
        preserveScroll: true,
        onSuccess: () => {
            addOpen.value = false;
            addForm.reset();
        },
    });
}

function submitEdit() {
    editForm.put(`/panel/oz/feedbacks/cabinets/${selectedCabinet.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
        },
    });
}

function confirmDelete() {
    router.delete(`/panel/oz/feedbacks/cabinets/${selectedCabinet.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
        },
    });
}
</script>

<template>
    <Head title="Управление отзывами Ozon" />

    <SubscriberLayout title="Управление отзывами" :breadcrumbs="breadcrumbs">
        <ToolPageHeader
            title="Управление отзывами"
            :description="limits.oz_feedbacks_clients !== null ? `Доступно кабинетов по тарифу: ${limits.oz_feedbacks_clients}` : ''"
        >
            <template #actions>
                <Button @click="openAdd">Добавить кабинет</Button>
            </template>
        </ToolPageHeader>

        <TestingBanner />

        <div class="space-y-4">
            <p v-if="!cabinets.length" class="text-sm text-muted-foreground">Кабинеты не добавлены</p>

            <div v-if="cabinets.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Card
                    v-for="cabinet in cabinets"
                    :key="cabinet.id"
                    class="flex flex-col justify-between p-4"
                >
                    <div class="space-y-2">
                        <a :href="cabinet.href" class="font-medium hover:text-primary">{{ cabinet.name }}</a>
                        <p v-if="cabinet.created_at" class="text-sm text-muted-foreground">
                            Добавлен: {{ cabinet.created_at }}
                        </p>
                        <p v-if="cabinet.client_id" class="text-sm text-muted-foreground">
                            Client ID: {{ cabinet.client_id }}
                        </p>
                    </div>
                    <div class="mt-4 flex gap-2">
                        <Button variant="outline" size="sm" @click="openEdit(cabinet)">
                            <Pencil class="mr-1 h-3.5 w-3.5" />
                            Изменить
                        </Button>
                        <Button variant="outline" size="sm" @click="openDelete(cabinet)">
                            <Trash2 class="mr-1 h-3.5 w-3.5" />
                            Удалить
                        </Button>
                    </div>
                </Card>
            </div>
        </div>

        <CabinetForm
            v-model:open="addOpen"
            :model-value="{ name: addForm.name, apikey: addForm.apikey, client_id: addForm.client_id }"
            title="Добавление кабинета"
            :processing="addForm.processing"
            marketplace="oz"
            @update:model-value="(v) => Object.assign(addForm, v)"
            @submit="submitAdd"
        />

        <CabinetForm
            v-model:open="editOpen"
            :model-value="{ name: editForm.name, apikey: editForm.apikey }"
            title="Редактирование кабинета"
            :processing="editForm.processing"
            marketplace="oz"
            @update:model-value="(v) => Object.assign(editForm, v)"
            @submit="submitEdit"
        >
            <label class="flex items-center gap-2">
                <Checkbox v-model="editForm.empty_answer" />
                Отвечать на отзывы без текста
            </label>
            <div class="space-y-2">
                <Label>Подпись к ответу</Label>
                <Textarea v-model="editForm.signature" :rows="2" placeholder="С уважением, …" />
            </div>
        </CabinetForm>

        <Dialog :open="deleteOpen" title="Удаление кабинета" @update:open="deleteOpen = $event">
            <p class="text-sm">
                Вы уверены, что хотите удалить кабинет <strong>{{ selectedCabinet?.name }}</strong>?
            </p>
            <template #footer>
                <Button variant="outline" @click="deleteOpen = false">Отмена</Button>
                <Button variant="destructive" @click="confirmDelete">Удалить</Button>
            </template>
        </Dialog>
    </SubscriberLayout>
</template>