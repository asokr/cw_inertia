<script setup>
import { FlexRender, getCoreRowModel, useVueTable } from "@tanstack/vue-table";
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
});

const table = useVueTable({
    get data() {
        return props.data;
    },
    get columns() {
        return props.columns;
    },
    getCoreRowModel: getCoreRowModel(),
});

const rows = computed(() => table.getRowModel().rows);
</script>

<template>
    <div class="overflow-x-auto rounded-md border">
        <table class="w-full caption-bottom text-sm">
            <thead class="border-b bg-muted/40">
                <tr v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id" class="border-b">
                    <th
                        v-for="header in headerGroup.headers"
                        :key="header.id"
                        class="h-9 px-3 text-left align-middle font-medium text-muted-foreground"
                    >
                        <FlexRender
                            v-if="!header.isPlaceholder"
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
                        class="h-9 px-3 align-middle"
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