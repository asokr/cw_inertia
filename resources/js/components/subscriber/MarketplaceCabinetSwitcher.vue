<script setup>
import { computed, ref } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
import { Check, ChevronDown, Pencil, Plus, Trash2 } from "lucide-vue-next";
import CabinetForm from "@/components/subscriber/tools/CabinetForm.vue";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";

const page = usePage();
const open = ref(false);

const wbCabinets = computed(() => page.props.wb_cabinets ?? []);
const selectedWb = computed(() => page.props.selected_wb_cabinet ?? null);
const ozCabinets = computed(() => page.props.oz_cabinets ?? []);
const selectedOz = computed(() => page.props.selected_oz_cabinet ?? null);

const apiKeyWarning = computed(
    () => page.props.wb_api_key_warning
        || "Для корректной работы всех инструментов платформы необходимо использовать персональный API-ключ Wildberries с полным набором разрешений."
);

/** Chips in the closed trigger: only marketplaces with a selected cabinet. */
const triggerChips = computed(() => {
    const chips = [];

    if (selectedWb.value?.name) {
        chips.push({
            key: "wb",
            badge: "WB",
            badgeClass: "bg-[#CB11AB]/15 text-[#CB11AB]",
            name: selectedWb.value.name,
        });
    }

    if (selectedOz.value?.name) {
        chips.push({
            key: "oz",
            badge: "ОЗ",
            badgeClass: "bg-[#005BFF]/15 text-[#005BFF]",
            name: selectedOz.value.name,
        });
    }

    return chips;
});

const triggerEmptyLabel = computed(() => {
    if (!wbCabinets.value.length && !ozCabinets.value.length) {
        return "Нет кабинетов";
    }
    return "Выберите кабинет";
});

// ─── dialog state ─────────────────────────────────────────────
const marketplace = ref("wb"); // active form marketplace
const addOpen = ref(false);
const editOpen = ref(false);
const deleteOpen = ref(false);
const selectedCabinet = ref(null);

const addForm = useForm({
    name: "",
    apikey: "",
    client_id: "",
    performance_client_id: "",
    performance_client_secret: "",
});
const editForm = useForm({
    name: "",
    apikey: "",
    client_id: "",
    performance_client_id: "",
    performance_client_secret: "",
});

function selectCabinet(mp, id) {
    const selected = mp === "oz" ? selectedOz.value : selectedWb.value;
    if (selected?.id === id) {
        open.value = false;
        return;
    }

    const url = mp === "oz" ? "/panel/oz/cabinets/select" : "/panel/wb/cabinets/select";
    router.post(
        url,
        { cabinet_id: id },
        {
            onFinish: () => {
                open.value = false;
            },
        },
    );
}

function openAdd(mp) {
    marketplace.value = mp;
    open.value = false;
    addForm.clearErrors();
    addForm.reset();
    addOpen.value = true;
}

function openEdit(mp, cabinet, event) {
    event?.stopPropagation();
    marketplace.value = mp;
    open.value = false;
    selectedCabinet.value = cabinet;
    editForm.clearErrors();
    editForm.name = cabinet.name;
    editForm.apikey = "";
    editForm.client_id = cabinet.client_id ?? "";
    editForm.performance_client_id = cabinet.performance_client_id ?? "";
    editForm.performance_client_secret = "";
    editOpen.value = true;
}

function openDelete(mp, cabinet, event) {
    event?.stopPropagation();
    marketplace.value = mp;
    open.value = false;
    selectedCabinet.value = cabinet;
    deleteOpen.value = true;
}

function baseUrl() {
    return marketplace.value === "oz" ? "/panel/oz/cabinets" : "/panel/wb/cabinets";
}

function submitAdd() {
    addForm.post(baseUrl(), {
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
    editForm.put(`${baseUrl()}/${selectedCabinet.value.id}`, {
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
    router.delete(`${baseUrl()}/${selectedCabinet.value.id}`, {
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
            class="max-w-[min(100vw-8rem,360px)] gap-2"
            title="Кабинеты"
            @click="open = !open"
        >
            <template v-if="triggerChips.length">
                <span
                    v-for="(chip, index) in triggerChips"
                    :key="chip.key"
                    class="flex min-w-0 items-center gap-1.5"
                    :class="index > 0 ? 'border-l border-border pl-2' : ''"
                >
                    <span
                        class="inline-flex h-5 shrink-0 items-center rounded px-1.5 text-[10px] font-bold tracking-wide"
                        :class="chip.badgeClass"
                    >
                        {{ chip.badge }}
                    </span>
                    <span class="truncate">{{ chip.name }}</span>
                </span>
            </template>
            <span v-else class="truncate text-muted-foreground">{{ triggerEmptyLabel }}</span>
            <ChevronDown class="h-3.5 w-3.5 shrink-0 opacity-60" />
        </Button>

        <div
            v-if="open"
            class="absolute right-0 z-[200] mt-1 w-80 rounded-md border border-border bg-card p-1 shadow-lg"
        >
            <!-- Wildberries -->
            <p class="flex items-center gap-2 px-2 py-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                <span class="inline-flex h-4 items-center rounded bg-[#CB11AB]/15 px-1 text-[9px] font-bold text-[#CB11AB]">
                    WB
                </span>
                Wildberries
            </p>

            <div
                v-for="cabinet in wbCabinets"
                :key="`wb-${cabinet.id}`"
                class="group flex items-center gap-1 rounded-md hover:bg-accent"
            >
                <button
                    type="button"
                    class="flex min-w-0 flex-1 items-center justify-between px-2 py-2 text-left text-sm"
                    @click="selectCabinet('wb', cabinet.id)"
                >
                    <span class="truncate">{{ cabinet.name }}</span>
                    <Check
                        v-if="selectedWb?.id === cabinet.id"
                        class="ml-2 h-3.5 w-3.5 shrink-0 text-primary"
                    />
                </button>
                <button
                    type="button"
                    class="rounded p-1.5 text-muted-foreground hover:bg-background hover:text-foreground"
                    title="Изменить"
                    @click="openEdit('wb', cabinet, $event)"
                >
                    <Pencil class="h-3.5 w-3.5" />
                </button>
                <button
                    type="button"
                    class="mr-1 rounded p-1.5 text-muted-foreground hover:bg-background hover:text-destructive"
                    title="Удалить"
                    @click="openDelete('wb', cabinet, $event)"
                >
                    <Trash2 class="h-3.5 w-3.5" />
                </button>
            </div>

            <p v-if="!wbCabinets.length" class="px-2 py-2 text-xs text-muted-foreground">
                Кабинеты Wildberries ещё не добавлены
            </p>

            <button
                type="button"
                class="flex w-full items-center gap-2 rounded-md px-2 py-2 text-sm text-muted-foreground hover:bg-accent hover:text-foreground"
                @click="openAdd('wb')"
            >
                <Plus class="h-3.5 w-3.5" />
                Добавить кабинет
            </button>

            <div class="my-1 border-t border-border" />

            <!-- Ozon -->
            <p class="flex items-center gap-2 px-2 py-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                <span class="inline-flex h-4 items-center rounded bg-[#005BFF]/15 px-1 text-[9px] font-bold text-[#005BFF]">
                    ОЗ
                </span>
                Ozon
            </p>

            <div
                v-for="cabinet in ozCabinets"
                :key="`oz-${cabinet.id}`"
                class="group flex items-center gap-1 rounded-md hover:bg-accent"
            >
                <button
                    type="button"
                    class="flex min-w-0 flex-1 items-center justify-between px-2 py-2 text-left text-sm"
                    @click="selectCabinet('oz', cabinet.id)"
                >
                    <span class="truncate">{{ cabinet.name }}</span>
                    <Check
                        v-if="selectedOz?.id === cabinet.id"
                        class="ml-2 h-3.5 w-3.5 shrink-0 text-primary"
                    />
                </button>
                <button
                    type="button"
                    class="rounded p-1.5 text-muted-foreground hover:bg-background hover:text-foreground"
                    title="Изменить"
                    @click="openEdit('oz', cabinet, $event)"
                >
                    <Pencil class="h-3.5 w-3.5" />
                </button>
                <button
                    type="button"
                    class="mr-1 rounded p-1.5 text-muted-foreground hover:bg-background hover:text-destructive"
                    title="Удалить"
                    @click="openDelete('oz', cabinet, $event)"
                >
                    <Trash2 class="h-3.5 w-3.5" />
                </button>
            </div>

            <p v-if="!ozCabinets.length" class="px-2 py-2 text-xs text-muted-foreground">
                Кабинеты Ozon ещё не добавлены
            </p>

            <button
                type="button"
                class="flex w-full items-center gap-2 rounded-md px-2 py-2 text-sm text-muted-foreground hover:bg-accent hover:text-foreground"
                @click="openAdd('oz')"
            >
                <Plus class="h-3.5 w-3.5" />
                Добавить кабинет
            </button>
        </div>

        <CabinetForm
            :open="addOpen"
            :title="marketplace === 'oz' ? 'Новый кабинет Ozon' : 'Новый кабинет Wildberries'"
            :description="marketplace === 'oz' ? 'Client ID и API-ключ из личного кабинета Ozon Seller' : apiKeyWarning"
            :marketplace="marketplace"
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
            :description="marketplace === 'oz' ? 'Оставьте API-ключ пустым, если не хотите его менять' : 'Оставьте API-ключ пустым, если не хотите его менять'"
            :marketplace="marketplace"
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
