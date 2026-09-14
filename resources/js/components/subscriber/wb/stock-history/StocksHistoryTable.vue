<script setup>
import { computed } from "vue";
import { ChevronDown, ChevronRight, Loader2 } from "lucide-vue-next";
import Checkbox from "@/components/ui/Checkbox.vue";
import { formatHistoryChartDate, stockHistoryRowKey } from "@/utils/wbStockHistoryRows";
import HistoryQtyCells from "./HistoryQtyCells.vue";
import ProductThumb from "./ProductThumb.vue";

const props = defineProps({
    dates: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    selectedKeys: { type: Object, default: () => ({}) },
    expanded: { type: Object, default: () => ({}) },
    details: { type: Object, default: () => ({}) },
    loadingKeys: { type: Object, default: () => ({}) },
    maxHeight: { type: String, default: "min(70vh, 56rem)" },
    fillHeight: { type: Boolean, default: false },
});

const emit = defineEmits(["toggle", "toggle-page", "toggle-expand", "toggle-warehouse"]);

const latestDate = computed(() => {
    const dates = Array.isArray(props.dates) ? props.dates : [];
    return dates.length ? dates[dates.length - 1] : null;
});

const headerDates = computed(() => [...(props.dates || [])].reverse());
const dateCount = computed(() => (Array.isArray(props.dates) ? props.dates.length : 0));

const allPageSelected = computed(() => (
    props.rows.length > 0 && props.rows.every((row) => Boolean(props.selectedKeys[stockHistoryRowKey(row)]))
));

function sizeKey(row) {
    return stockHistoryRowKey(row);
}

function warehouseRow(row, warehouse) {
    return {
        ...row,
        warehouse_key: warehouse.warehouse_key,
        warehouse_name: warehouse.warehouse_name,
        series: warehouse.series || [],
        quantity: warehouse.quantity,
    };
}

function isSelected(row) {
    return Boolean(props.selectedKeys[stockHistoryRowKey(row)]);
}

function stickyClass(row) {
    return isSelected(row) ? "bg-primary/5 group-hover:bg-primary/10" : "bg-card group-hover:bg-muted";
}
</script>

<template>
    <div
        class="overflow-auto rounded-lg border bg-card"
        :class="fillHeight ? 'min-h-0 flex-1' : ''"
        :style="fillHeight ? undefined : { maxHeight }"
    >
        <table class="min-w-full border-collapse text-sm">
            <thead>
                <tr class="border-b">
                    <th class="sticky left-0 top-0 z-40 w-10 bg-card px-2 py-2">
                        <Checkbox
                            :model-value="allPageSelected"
                            :disabled="rows.length === 0"
                            @update:model-value="emit('toggle-page', $event)"
                        />
                    </th>
                    <th class="sticky left-10 top-0 z-30 w-14 bg-card px-2 py-2"></th>
                    <th class="sticky left-24 top-0 z-20 min-w-[9rem] bg-card px-3 py-2 text-left font-medium">Предмет</th>
                    <th class="sticky top-0 z-20 min-w-[8rem] bg-card px-3 py-2 text-left font-medium">Артикул продавца</th>
                    <th class="sticky top-0 z-20 min-w-[7rem] bg-card px-3 py-2 text-left font-medium">Артикул WB</th>
                    <th class="sticky top-0 z-20 min-w-[5rem] bg-card px-3 py-2 text-left font-medium">Размер</th>
                    <th class="sticky top-0 z-20 min-w-[6.5rem] bg-card px-2 py-2 text-center font-medium">Общие остатки</th>
                    <th class="sticky top-0 z-20 min-w-[6.5rem] bg-card px-2 py-2 text-center font-medium">В пути до получателей</th>
                    <th class="sticky top-0 z-20 min-w-[6.5rem] bg-card px-2 py-2 text-center font-medium">В пути возвраты</th>
                    <th
                        v-for="date in headerDates"
                        :key="date"
                        scope="col"
                        class="sticky top-0 z-20 min-w-[3.25rem] border-l bg-card px-1.5 py-2 text-center font-medium tabular-nums"
                    >
                        {{ formatHistoryChartDate(date) }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <template v-for="row in rows" :key="sizeKey(row)">
                    <tr
                        class="group cursor-pointer border-b"
                        :class="isSelected(row) ? 'bg-primary/5' : 'hover:bg-accent/40'"
                        @click="emit('toggle-expand', row)"
                    >
                        <td class="sticky left-0 z-20 w-10 px-2 py-2" :class="stickyClass(row)" @click.stop>
                            <Checkbox
                                :model-value="isSelected(row)"
                                @update:model-value="emit('toggle', row)"
                            />
                        </td>
                        <td class="sticky left-10 z-10 w-14 px-2 py-2" :class="stickyClass(row)">
                            <ProductThumb :src="row.image_url" />
                        </td>
                        <td class="sticky left-24 z-10 min-w-[9rem] max-w-[14rem] px-3 py-2" :class="stickyClass(row)">
                            <div class="flex items-center gap-2">
                                <ChevronDown v-if="expanded[sizeKey(row)]" class="h-4 w-4 shrink-0 text-muted-foreground" />
                                <ChevronRight v-else class="h-4 w-4 shrink-0 text-muted-foreground" />
                                <p class="truncate font-medium">{{ row.subject || "—" }}</p>
                            </div>
                        </td>
                        <td class="min-w-[8rem] px-3 py-2">
                            <p class="truncate">{{ row.vendor_code || "—" }}</p>
                        </td>
                        <td class="min-w-[7rem] px-3 py-2 tabular-nums">{{ row.nm_id || "—" }}</td>
                        <td class="min-w-[5rem] px-3 py-2">{{ row.tech_size || "—" }}</td>
                        <td class="min-w-[6.5rem] px-2 py-2 text-center tabular-nums font-medium">{{ row.quantity ?? 0 }}</td>
                        <td class="min-w-[6.5rem] px-2 py-2 text-center tabular-nums font-medium">{{ row.in_way_to_client ?? 0 }}</td>
                        <td class="min-w-[6.5rem] px-2 py-2 text-center tabular-nums font-medium">{{ row.in_way_from_client ?? 0 }}</td>
                        <HistoryQtyCells
                            :dates="dates"
                            :values="row.series || []"
                            :latest-date="latestDate"
                        />
                    </tr>

                    <tr v-if="expanded[sizeKey(row)] && loadingKeys[sizeKey(row)]">
                        <td class="sticky left-0 z-10 bg-card px-3 py-2" colspan="2" />
                        <td :colspan="7 + dateCount" class="px-3 py-2 text-sm text-muted-foreground">
                            <span class="inline-flex items-center gap-2">
                                <Loader2 class="h-4 w-4 animate-spin" />
                                Загружаем склады…
                            </span>
                        </td>
                    </tr>

                    <tr
                        v-else-if="expanded[sizeKey(row)] && !(details[sizeKey(row)]?.warehouses || []).length"
                    >
                        <td class="sticky left-0 z-10 bg-card px-3 py-2" colspan="2" />
                        <td :colspan="7 + dateCount" class="px-3 py-2 text-sm text-muted-foreground">
                            В истории остатков нет разбивки по складам.
                        </td>
                    </tr>

                    <tr
                        v-for="warehouse in (expanded[sizeKey(row)] ? (details[sizeKey(row)]?.warehouses || []) : [])"
                        :key="`${sizeKey(row)}-${warehouse.warehouse_key}`"
                        class="border-b bg-background/60"
                    >
                        <td class="sticky left-0 z-20 w-10 bg-card px-2 py-2" @click.stop>
                            <Checkbox
                                :model-value="isSelected(warehouseRow(row, warehouse))"
                                @update:model-value="emit('toggle-warehouse', warehouseRow(row, warehouse))"
                            />
                        </td>
                        <td class="sticky left-10 z-10 bg-card px-2 py-2" />
                        <td class="sticky left-24 z-10 bg-card px-3 py-2 pl-10">
                            <p class="truncate text-sm">{{ warehouse.warehouse_name }}</p>
                            <p class="text-xs text-muted-foreground">сейчас {{ warehouse.quantity ?? 0 }}</p>
                        </td>
                        <td colspan="3" />
                        <td class="px-2 py-2 text-center tabular-nums">{{ warehouse.quantity ?? 0 }}</td>
                        <td colspan="2" />
                        <HistoryQtyCells
                            :dates="dates"
                            :values="warehouse.series || []"
                            :latest-date="latestDate"
                        />
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
