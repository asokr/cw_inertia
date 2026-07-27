<script setup>
import { computed, ref } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
import { Check, ChevronDown, Pencil, Plus, Trash2 } from "lucide-vue-next";
import CabinetForm from "@/components/subscriber/tools/CabinetForm.vue";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";

const page = usePage();
const open = ref(false);

const cabinets = computed(() => page.props.wb_cabinets ?? []);
const selected = computed(() => page.props.selected_wb_cabinet ?? null);
const apiKeyWarning = computed(
    () => page.props.wb_api_key_warning
        || "Для корректной работы всех инструментов платформы необходимо использовать персональный API-ключ Wildberries с полным набором разрешений."
);

const label = computed(() => {
    if (selected.value?.name) {
        return selected.value.name;
    }
    if (!cabinets.value.length) {
        return "Нет кабинетов";
    }
    return "Выберите кабинет";
});

const addOpen = ref(false);
const editOpen = ref(false);
const deleteOpen = ref(false);
const selectedCabinet = ref(null);

const addForm = useForm({ name: "", apikey: "" });
const editForm = useForm({ name: "", apikey: "" });

function selectCabinet(id) {
    if (selected.value?.id === id) {
        open.value = false;
        return;
    }

    router.post(
        "/panel/wb/cabinets/select",
        { cabinet_id: id },
        {
            preserveScroll: true,
            onFinish: () => {
                open.value = false;
            },
        }
    );
}

function openAdd() {
    open.value = false;
    addForm.clearErrors();
    addForm.reset();
    addOpen.value = true;
}

function openEdit(cabinet, event) {
    event?.stopPropagation();
    open.value = false;
    selectedCabinet.value = cabinet;
    editForm.clearErrors();
    editForm.name = cabinet.name;
    editForm.apikey = "";
    editOpen.value = true;
}

function openDelete(cabinet, event) {
    event?.stopPropagation();
    open.value = false;
    selectedCabinet.value = cabinet;
    deleteOpen.value = true;
}

function submitAdd() {
    addForm.post("/panel/wb/cabinets", {
        preserveScroll: true,
        onSuccess: () => {
            addOpen.value = false;
            addForm.reset();
        },
    });
}

function submitEdit() {
    if (!selectedCabinet.value) {
        return;
    }
    editForm.put(`/panel/wb/cabinets/${selectedCabinet.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
        },
    });
}

function confirmDelete() {
    if (!selectedCabinet.value) {
        return;
    }
    router.delete(`/panel/wb/cabinets/${selectedCabinet.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
            selectedCabinet.value = null;
        },
    });
}

function onBlur(event) {
    if (!event.currentTarget.contains(event.relatedTarget)) {
        open.value = false;
    }
}
</script>

<template>
    <div class="relative z-[100]" @focusout="onBlur">
        <Button
            type="button"
            variant="outline"
            size="sm"
            class="max-w-[260px] gap-2"
            title="Кабинет Wildberries"
            @click="open = !open"
        >
            <span
                class="inline-flex h-5 shrink-0 items-center rounded bg-[#CB11AB]/15 px-1.5 text-[10px] font-bold tracking-wide text-[#CB11AB]"
            >
                WB
            </span>
            <span class="truncate">{{ label }}</span>
            <ChevronDown class="h-3.5 w-3.5 shrink-0 opacity-60" />
        </Button>

        <div
            v-if="open"
            class="absolute right-0 z-[200] mt-1 w-80 rounded-md border border-border bg-card p-1 shadow-lg"
        >
            <p class="flex items-center gap-2 px-2 py-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                <span class="inline-flex h-4 items-center rounded bg-[#CB11AB]/15 px-1 text-[9px] font-bold text-[#CB11AB]">
                    WB
                </span>
                Кабинет Wildberries
            </p>

            <div
                v-for="cabinet in cabinets"
                :key="cabinet.id"
                class="group flex items-center gap-1 rounded-md hover:bg-accent"
            >
                <button
                    type="button"
                    class="flex min-w-0 flex-1 items-center justify-between px-2 py-2 text-left text-sm"
                    @click="selectCabinet(cabinet.id)"
                >
                    <span class="truncate">{{ cabinet.name }}</span>
                    <Check
                        v-if="selected?.id === cabinet.id"
                        class="ml-2 h-3.5 w-3.5 shrink-0 text-primary"
                    />
                </button>
                <button
                    type="button"
                    class="rounded p-1.5 text-muted-foreground hover:bg-background hover:text-foreground"
                    title="Изменить"
                    @click="openEdit(cabinet, $event)"
                >
                    <Pencil class="h-3.5 w-3.5" />
                </button>
                <button
                    type="button"
                    class="mr-1 rounded p-1.5 text-muted-foreground hover:bg-background hover:text-destructive"
                    title="Удалить"
                    @click="openDelete(cabinet, $event)"
                >
                    <Trash2 class="h-3.5 w-3.5" />
                </button>
            </div>

            <p v-if="!cabinets.length" class="px-2 py-2 text-xs text-muted-foreground">
                Кабинеты Wildberries ещё не добавлены
            </p>

            <div class="my-1 border-t border-border" />

            <button
                type="button"
                class="flex w-full items-center gap-2 rounded-md px-2 py-2 text-sm text-muted-foreground hover:bg-accent hover:text-foreground"
                @click="openAdd"
            >
                <Plus class="h-3.5 w-3.5" />
                Добавить кабинет WB
            </button>
        </div>

        <CabinetForm
            :open="addOpen"
            title="Новый кабинет Wildberries"
            :description="apiKeyWarning"
            :model-value="addForm"
            :processing="addForm.processing"
            :errors="addForm.errors"
            @update:open="addOpen = $event"
            @update:model-value="Object.assign(addForm, $event)"
            @submit="submitAdd"
        />

        <CabinetForm
            :open="editOpen"
            title="Изменить кабинет"
            description="Оставьте API-ключ пустым, если не хотите его менять"
            :model-value="editForm"
            :processing="editForm.processing"
            :errors="editForm.errors"
            @update:open="editOpen = $event"
            @update:model-value="Object.assign(editForm, $event)"
            @submit="submitEdit"
        />

        <Dialog
            :open="deleteOpen"
            title="Удалить кабинет?"
            description="Данные инструментов, привязанные к кабинету, могут стать недоступны. Удаление необратимо."
            @update:open="deleteOpen = $event"
        >
            <div class="flex justify-end gap-2">
                <Button variant="outline" @click="deleteOpen = false">Отмена</Button>
                <Button variant="destructive" @click="confirmDelete">Удалить</Button>
            </div>
        </Dialog>
    </div>
</template>
