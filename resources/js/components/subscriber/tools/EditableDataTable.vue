<script setup>
import {
    FlexRender,
    getCoreRowModel,
    getSortedRowModel,
    useVueTable,
} from "@tanstack/vue-table";
import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-vue-next";
import { computed } from "vue";

const props = defineProps({
    columns: {
        type: Array,
        required: true,
    },
    data: {
        type: Array,
        default: () => [],
    },
    emptyText: {
        type: String,
        default: "Нет данных",
    },
    pinnedColumns: {
        type: Object,
        default: () => ({ left: [], right: [] }),
    },
    manualSorting: {
        type: Boolean,
        default: false,
    },
    sorting: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(["sort-change"]);

const table = useVueTable({
    get data() {
        return props.data;
    },
    get columns() {
        return props.columns;
    },
    getCoreRowModel: getCoreRowModel(),
    ...(props.manualSorting
        ? {
              manualSorting: true,
              onSortingChange: (updater) => {
                  const next = typeof updater === "function" ? updater(props.sorting) : updater;
                  emit("sort-change", next);
              },
          }
        : {
              getSortedRowModel: getSortedRowModel(),
              enableSorting: true,
          }),
    state: props.manualSorting
        ? {
              sorting: props.sorting,
              columnPinning: props.pinnedColumns,
          }
        : undefined,
    initialState: props.manualSorting
        ? undefined
        : {
              columnPinning: props.pinnedColumns,
          },
});

const rows = computed(() => table.getRowModel().rows);

function columnStyle(meta) {
    if (!meta?.backgroundColor && !meta?.fontColor) {
        return undefined;
    }

    return {
        backgroundColor: meta.backgroundColor ?? undefined,
        color: meta.fontColor ?? undefined,
    };
}

function groupClass(meta) {
    if (meta?.backgroundColor || meta?.fontColor) {
        return "";
    }

    const map = {
        product: "bg-[#c6efce]",
        cost: "bg-[#fff8e1]",
        logistics: "bg-[#dce6f1]",
        extra: "bg-[#fce4d6]",
        finance: "bg-[#e4dfec]",
        beige: "bg-[#f5e6d3]",
        result: "bg-[#fff2cc]",
        white: "bg-background",
    };

    return map[meta?.group] ?? "";
}

function headerClass(header) {
    const pinned = header.column.getIsPinned();
    const group = groupClass(header.column.columnDef.meta);

    if (pinned === "left") {
        return `sticky left-0 z-10 backdrop-blur ${group || "bg-muted/90"}`;
    }

    if (pinned === "right") {
        return `sticky right-0 z-10 backdrop-blur ${group || "bg-muted/90"}`;
    }

    return group;
}

function cellClass(cell) {
    const pinned = cell.column.getIsPinned();
    const group = groupClass(cell.column.columnDef.meta);

    if (pinned === "left") {
        return `sticky left-0 z-10 ${group || "bg-card"}`;
    }

    if (pinned === "right") {
        return `sticky right-0 z-10 ${group || "bg-card"}`;
    }

    return group;
}

function sortIcon(header) {
    const sorted = header.column.getIsSorted();

    if (sorted === "asc") {
        return ArrowUp;
    }

    if (sorted === "desc") {
        return ArrowDown;
    }

    return ArrowUpDown;
}
</script>

<template>
    <div class="overflow-x-auto rounded-md border">
        <table class="w-full caption-bottom text-sm">
            <thead class="border-b bg-muted/40">
                <tr v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id">
                    <th
                        v-for="header in headerGroup.headers"
                        :key="header.id"
                        class="h-9 whitespace-nowrap px-3 text-left align-middle font-medium text-muted-foreground"
                        :class="headerClass(header)"
                        :style="columnStyle(header.column.columnDef.meta)"
                    >
                        <button
                            v-if="!header.isPlaceholder && header.column.getCanSort()"
                            type="button"
                            class="inline-flex items-center gap-1 hover:text-foreground"
                            @click="header.column.toggleSorting()"
                        >
                            <FlexRender
                                :render="header.column.columnDef.header"
                                :props="header.getContext()"
                            />
                            <component :is="sortIcon(header)" class="h-3.5 w-3.5 opacity-60" />
                        </button>
                        <FlexRender
                            v-else-if="!header.isPlaceholder"
                            :render="header.column.columnDef.header"
                            :props="header.getContext()"
                        />
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in rows"
                    :key="row.id"
                    class="border-b transition-colors hover:bg-muted/30"
                >
                    <td
                        v-for="cell in row.getVisibleCells()"
                        :key="cell.id"
                        class="h-9 whitespace-nowrap px-3 align-middle"
                        :class="cellClass(cell)"
                        :style="columnStyle(cell.column.columnDef.meta)"
                    >
                        <FlexRender
                            :render="cell.column.columnDef.cell"
                            :props="cell.getContext()"
                        />
                    </td>
                </tr>
                <tr v-if="rows.length === 0">
                    <td :colspan="columns.length" class="h-16 px-3 text-center text-muted-foreground">
                        {{ emptyText }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>