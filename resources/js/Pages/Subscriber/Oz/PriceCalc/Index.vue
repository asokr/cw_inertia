<script setup>
import { ref } from "vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { Pencil, Trash2 } from "lucide-vue-next";
import PriceCalcFaq from "@/components/subscriber/oz/price-calc/PriceCalcFaq.vue";
import CabinetForm from "@/components/subscriber/tools/CabinetForm.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";

defineProps({
    cabinets: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({}) },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Ценообразование Ozon" },
];

const addOpen = ref(false);
const editOpen = ref(false);
const deleteOpen = ref(false);
const selectedCabinet = ref(null);

const addForm = useForm({ name: "", client_id: "", apikey: "" });
const editForm = useForm({ name: "", client_id: "", apikey: "" });

function openAdd() {
    addForm.reset();
    addOpen.value = true;
}

function openEdit(cabinet) {
    selectedCabinet.value = cabinet;
    editForm.name = cabinet.name;
    editForm.client_id = cabinet.client_id ?? "";
    editForm.apikey = cabinet.apikey ?? "";
    editOpen.value = true;
}

function openDelete(cabinet) {
    selectedCabinet.value = cabinet;
    deleteOpen.value = true;
}

function submitAdd() {
    addForm.post("/panel/oz/price-calc/cabinets", {
        preserveScroll: true,
        onSuccess: () => {
            addOpen.value = false;
            addForm.reset();
        },
    });
}

function submitEdit() {
    editForm.put(`/panel/oz/price-calc/cabinets/${selectedCabinet.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
        },
    });
}

function confirmDelete() {
    router.delete(`/panel/oz/price-calc/cabinets/${selectedCabinet.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
        },
    });
}
</script>

<template>
    <Head title="Ценообразование Ozon" />

    <SubscriberLayout title="Ценообразование Ozon" :breadcrumbs="breadcrumbs">
        <ToolPageHeader
            title="Ценообразование Ozon"
            :description="limits.oz_price_calc_clients !== null && limits.oz_price_calc_clients !== undefined ? `Доступно кабинетов по тарифу: ${limits.oz_price_calc_clients}` : ''"
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
                        <p v-if="cabinet.client_id" class="text-sm text-muted-foreground">
                            Client ID: {{ cabinet.client_id }}
                        </p>
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

            <PriceCalcFaq />
        </div>

        <CabinetForm
            v-model:open="addOpen"
            :model-value="{ name: addForm.name, client_id: addForm.client_id, apikey: addForm.apikey }"
            title="Добавление кабинета"
            :processing="addForm.processing"
            marketplace="oz"
            @update:model-value="(v) => Object.assign(addForm, v)"
            @submit="submitAdd"
        />

        <CabinetForm
            v-model:open="editOpen"
            :model-value="{ name: editForm.name, client_id: editForm.client_id, apikey: editForm.apikey }"
            title="Редактирование кабинета"
            :processing="editForm.processing"
            marketplace="oz"
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