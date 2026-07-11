<script setup>
import { computed, h, ref, watch } from "vue";
import EditableDataTable from "@/components/subscriber/tools/EditableDataTable.vue";
import Checkbox from "@/components/ui/Checkbox.vue";
import Input from "@/components/ui/Input.vue";
import {
    formatCount,
    formatPercent,
    formatRub,
    rowProfitClass,
} from "@/utils/promoCalculatorFormatters";
import ExportButton from "@/components/subscriber/wb/promo-calculator/ExportButton.vue";

const props = defineProps({
    items: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:selected", "error"]);

const search = ref("");
const selectedIds = ref(new Set());

const filteredItems = computed(() => {
    const query = search.value.trim().toLowerCase();
    if (!query) return props.items;

    return props.items.filter((item) => {
        const vendor = String(item.vendor_art ?? "").toLowerCase();
        const nmid = String(item.nm_id ?? "");
        return vendor.includes(query) || nmid.includes(query);
    });
});

watch(
    () => props.items,
    (items) => {
        const positive = items.filter((item) => Number(item.profit) > 0);
        selectedIds.value = new Set(positive.map((item) => item.nm_id));
        emitSelected();
    },
    { immediate: true },
);

function emitSelected() {
    const selected = props.items.filter((item) => selectedIds.value.has(item.nm_id));
    emit("update:selected", selected);
}

function toggleRow(nmId, checked) {
    if (checked) {
        selectedIds.value.add(nmId);
    } else {
        selectedIds.value.delete(nmId);
    }
    emitSelected();
}

function toggleAll(checked) {
    if (checked) {
        filteredItems.value.forEach((item) => selectedIds.value.add(item.nm_id));
    } else {
        filteredItems.value.forEach((item) => selectedIds.value.delete(item.nm_id));
    }
    emitSelected();
}

const allVisibleSelected = computed(() => {
    if (!filteredItems.value.length) return false;
    return filteredItems.value.every((item) => selectedIds.value.has(item.nm_id));
});

const columns = computed(() => [
    {
        id: "select",
        header: () => h(Checkbox, {
            modelValue: allVisibleSelected.value,
            "onUpdate:modelValue": toggleAll,
        }),
        enableSorting: false,
        cell: ({ row }) => h(Checkbox, {
            modelValue: selectedIds.value.has(row.original.nm_id),
            "onUpdate:modelValue": (checked) => toggleRow(row.original.nm_id, checked),
        }),
    },
    {
        accessorKey: "vendor_art",
        header: "Артикул поставщика",
        enableSorting: true,
        cell: ({ row }) => row.original.vendor_art || "—",
    },
    {
        accessorKey: "nm_id",
        header: "Артикул WB",
        enableSorting: true,
    },
    {
        accessorKey: "plan_price",
        header: "Плановая цена",
        enableSorting: true,
        cell: ({ row }) => formatRub(row.original.plan_price),
    },
    {
        accessorKey: "current_price",
        header: "Текущая цена",
        enableSorting: true,
        cell: ({ row }) => formatRub(row.original.current_price),
    },
    {
        accessorKey: "min_price",
        header: "Мин. цена",
        enableSorting: true,
        cell: ({ row }) => formatRub(row.original.min_price),
    },
    {
        id: "discount",
        header: "Скидка",
        enableSorting: false,
        cell: ({ row }) => {
            const item = row.original;
            if (item.change_discount) {
                return h("span", {}, [
                    h("span", { class: "text-xs line-through text-muted-foreground" }, `${item.wb_discount} %`),
                    h("span", { class: "ml-2 font-medium" }, `${item.change_discount} %`),
                ]);
            }
            return `${item.wb_discount} %`;
        },
    },
    {
        accessorKey: "margin",
        header: "Маржа",
        enableSorting: true,
        cell: ({ row }) => formatRub(row.original.margin),
    },
    {
        accessorKey: "profit",
        header: "Рентабельность",
        enableSorting: true,
        cell: ({ row }) => formatPercent(row.original.profit),
    },
    {
        accessorKey: "stock",
        header: "Остаток",
        enableSorting: true,
        cell: ({ row }) => formatCount(row.original.stock),
    },
]);
</script>

<template>
    <div v-if="items.length" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-lg font-semibold">Рассчёт</h3>
            <ExportButton :data="items" @error="emit('error', $event)" />
        </div>

        <Input v-model="search" placeholder="Найти по артикулу или NMID" class="max-w-sm" />

        <EditableDataTable
            :columns="columns"
            :data="filteredItems"
            max-height="32rem"
            :get-row-class="(item) => rowProfitClass(item.profit)"
        />
    </div>
</template>