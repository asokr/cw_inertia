<script setup>
import { ref } from "vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { Pencil, Trash2 } from "lucide-vue-next";
import CabinetForm from "@/components/subscriber/tools/CabinetForm.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";

const props = defineProps({
    cabinets: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({}) },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Репрайсер Wildberries" },
];

const addOpen = ref(false);
const editOpen = ref(false);
const deleteOpen = ref(false);
const selectedCabinet = ref(null);

const addForm = useForm({ name: "", apikey: "" });
const editForm = useForm({ name: "", apikey: "" });

function openAdd() {
    addForm.reset();
    addOpen.value = true;
}

function openEdit(cabinet) {
    selectedCabinet.value = cabinet;
    editForm.name = cabinet.name;
    editForm.apikey = cabinet.apikey ?? "";
    editOpen.value = true;
}

function openDelete(cabinet) {
    selectedCabinet.value = cabinet;
    deleteOpen.value = true;
}

function submitAdd() {
    addForm.post("/panel/wb/repricer/cabinets", {
        preserveScroll: true,
        onSuccess: () => {
            addOpen.value = false;
            addForm.reset();
        },
    });
}

function submitEdit() {
    editForm.put(`/panel/wb/repricer/cabinets/${selectedCabinet.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
        },
    });
}

function confirmDelete() {
    router.delete(`/panel/wb/repricer/cabinets/${selectedCabinet.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
        },
    });
}
</script>

<template>
    <Head title="Репрайсер Wildberries" />

    <SubscriberLayout title="Репрайсер Wildberries" :breadcrumbs="breadcrumbs">
        <ToolPageHeader
            title="Репрайсер Wildberries"
            :description="limits.repricer_nmid !== null ? `Доступно номенклатур по тарифу: ${limits.repricer_nmid}` : ''"
        >
            <template #actions>
                <Button @click="openAdd">Добавить кабинет</Button>
            </template>
        </ToolPageHeader>

        <div class="space-y-4">
            <p v-if="!cabinets.length" class="text-sm text-muted-foreground">Кабинеты не добавлены</p>

            <div v-if="cabinets.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Card
                    v-for="cabinet in cabinets"
                    :key="cabinet.id"
                    class="flex flex-col justify-between p-4"
                >
                    <div class="space-y-2">
                        <Link :href="cabinet.href" class="font-medium hover:text-primary">{{ cabinet.name }}</Link>
                        <p v-if="cabinet.created_at" class="text-sm text-muted-foreground">
                            Добавлен: {{ cabinet.created_at }}
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
            :model-value="{ name: addForm.name, apikey: addForm.apikey }"
            title="Добавление кабинета"
            :processing="addForm.processing"
            marketplace="wb"
            @update:model-value="(v) => Object.assign(addForm, v)"
            @submit="submitAdd"
        />

        <CabinetForm
            v-model:open="editOpen"
            :model-value="{ name: editForm.name, apikey: editForm.apikey }"
            title="Редактирование кабинета"
            :processing="editForm.processing"
            marketplace="wb"
            @update:model-value="(v) => Object.assign(editForm, v)"
            @submit="submitEdit"
        />

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