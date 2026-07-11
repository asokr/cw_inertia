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
    maxHeight: {
        type: String,
        default: null,
    },
    getRowClass: {
        type: Function,
        default: null,
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

function headerBackground(header) {
    const group = groupClass(header.column.columnDef.meta);

    return group || "bg-muted/90";
}

function pinnedPositionStyle(target, kind) {
    const column = target.column;
    const pinned = column.getIsPinned();

    if (pinned === "left") {
        const offset = kind === "header" ? target.getStart("left") : column.getStart("left");

        return { left: `${offset}px` };
    }

    if (pinned === "right") {
        const offset = kind === "header" ? target.getAfter("right") : column.getAfter("right");

        return { right: `${offset}px` };
    }

    return undefined;
}

function headerStyles(header) {
    return {
        ...columnStyle(header.column.columnDef.meta),
        ...pinnedPositionStyle(header, "header"),
    };
}

function cellStyles(cell) {
    return {
        ...columnStyle(cell.column.columnDef.meta),
        ...pinnedPositionStyle(cell, "cell"),
    };
}

function headerClass(header) {
    const pinned = header.column.getIsPinned();
    const bg = headerBackground(header);

    if (pinned === "left") {
        return `sticky top-0 z-20 backdrop-blur ${bg}`;
    }

    if (pinned === "right") {
        return `sticky top-0 z-20 backdrop-blur ${bg}`;
    }

    return `sticky top-0 z-10 backdrop-blur ${bg}`;
}

function cellClass(cell) {
    const pinned = cell.column.getIsPinned();
    const group = groupClass(cell.column.columnDef.meta);

    if (pinned === "left") {
        return `sticky z-10 ${group || "bg-card"}`;
    }

    if (pinned === "right") {
        return `sticky z-10 ${group || "bg-card"}`;
    }

    return group;
}

function rowClass(row) {
    const base = "border-b transition-colors hover:bg-muted/30";
    const custom = props.getRowClass?.(row.original);

    return custom ? `${base} ${custom}` : base;
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
    <div
        class="rounded-md border"
        :class="maxHeight ? 'overflow-auto' : 'overflow-x-auto'"
        :style="maxHeight ? { maxHeight } : undefined"
    >
        <table class="w-full caption-bottom text-sm">
            <thead class="border-b">
                <tr v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id">
                    <th
                        v-for="header in headerGroup.headers"
                        :key="header.id"
                        class="h-9 whitespace-nowrap px-3 text-left align-middle font-medium text-muted-foreground"
                        :class="headerClass(header)"
                        :style="headerStyles(header)"
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
                    :class="rowClass(row)"
                >
                    <td
                        v-for="cell in row.getVisibleCells()"
                        :key="cell.id"
                        class="h-9 whitespace-nowrap px-3 align-middle"
                        :class="cellClass(cell)"
                        :style="cellStyles(cell)"
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