<script setup>
import { computed, h, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { Pencil, Trash2 } from "lucide-vue-next";
import EditableDataTable from "@/components/subscriber/tools/EditableDataTable.vue";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import { formatTermValue, modifierLabel, normalizeTerms, priceTypeLabel } from "@/utils/repricerTerms";

const props = defineProps({
    items: { type: Array, default: () => [] },
    cabinetId: { type: [Number, String], required: true },
    logsUrl: { type: String, required: true },
});

const emit = defineEmits(["edit", "open-logs"]);

const deleteOpen = ref(false);
const selected = ref(null);
const periodsOpen = ref(false);
const periodsItem = ref(null);

function openPeriods(item) {
    periodsItem.value = item;
    periodsOpen.value = true;
}

function confirmDelete(item) {
    selected.value = item;
    deleteOpen.value = true;
}

function destroy() {
    router.delete(`/panel/wb/repricer/cabinets/${props.cabinetId}/time/${selected.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
        },
    });
}

const columns = computed(() => [
    {
        accessorKey: "nmID",
        header: "nmID",
        enableSorting: true,
        cell: ({ row }) => h(
            "button",
            {
                type: "button",
                class: "text-primary underline hover:no-underline",
                onClick: () => emit("open-logs", row.original.nmID),
            },
            () => row.original.nmID,
        ),
    },
    {
        accessorKey: "base_value",
        header: "База",
        enableSorting: true,
        cell: ({ row }) => (
            row.original.base_value != null
                ? `${row.original.base_value} ₽`
                : "После первого входа"
        ),
    },
    {
        accessorKey: "base_discount",
        header: "Скидка",
        enableSorting: true,
        cell: ({ row }) => (
            row.original.base_discount != null ? `${row.original.base_discount}%` : "—"
        ),
    },
    {
        accessorKey: "price_type",
        header: "Тип",
        enableSorting: true,
        cell: ({ row }) => priceTypeLabel(row.original.price_type),
    },
    {
        id: "modifier",
        header: "Модификатор",
        enableSorting: false,
        cell: ({ row }) => modifierLabel(row.original),
    },
    {
        id: "periods",
        header: "Периоды",
        enableSorting: false,
        cell: ({ row }) => h(
            Button,
            {
                variant: "outline",
                size: "sm",
                onClick: () => openPeriods(row.original),
            },
            () => `Периоды (${normalizeTerms(row.original.terms).length})`,
        ),
    },
    {
        accessorKey: "active",
        header: "Активна",
        enableSorting: true,
        cell: ({ row }) => (row.original.active ? "Да" : "Нет"),
    },
    {
        accessorKey: "status",
        header: "Статус",
        enableSorting: true,
        cell: ({ row }) => (row.original.status ? "Вкл" : "Выкл"),
    },
    {
        id: "actions",
        header: "Действия",
        enableSorting: false,
        cell: ({ row }) => h("div", { class: "flex gap-1" }, [
            h(
                Button,
                {
                    variant: "outline",
                    size: "sm",
                    onClick: () => emit("edit", row.original),
                },
                () => h(Pencil, { class: "h-3.5 w-3.5" }),
            ),
            h(
                Button,
                {
                    variant: "outline",
                    size: "sm",
                    onClick: () => confirmDelete(row.original),
                },
                () => h(Trash2, { class: "h-3.5 w-3.5" }),
            ),
        ]),
    },
]);
</script>

<template>
    <EditableDataTable
        :columns="columns"
        :data="items"
        max-height="calc(100dvh - 14rem)"
        empty-text="Номенклатура не добавлена"
    />

    <Dialog :open="periodsOpen" title="Периоды стратегии" @update:open="periodsOpen = $event">
        <div v-if="periodsItem" class="space-y-2 text-sm">
            <div
                v-for="(period, index) in normalizeTerms(periodsItem.terms)"
                :key="index"
                class="grid grid-cols-4 gap-2 border-b py-2"
            >
                <span>{{ index + 1 }}</span>
                <span>{{ period.start }}</span>
                <span>{{ period.end }}</span>
                <span>{{ formatTermValue(periodsItem, period.value) }}</span>
            </div>
        </div>
    </Dialog>

    <Dialog :open="deleteOpen" title="Удаление" @update:open="deleteOpen = $event">
        <p class="text-sm">Удалить номенклатуру <strong>{{ selected?.nmID }}</strong>?</p>
        <template #footer>
            <Button variant="outline" @click="deleteOpen = false">Отмена</Button>
            <Button variant="destructive" @click="destroy">Удалить</Button>
        </template>
    </Dialog>
</template>