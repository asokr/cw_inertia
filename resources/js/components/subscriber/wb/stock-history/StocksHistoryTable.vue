<script setup>
import { computed, ref } from "vue";
import { ChevronDown, ChevronRight } from "lucide-vue-next";
import Checkbox from "@/components/ui/Checkbox.vue";
import { formatHistoryChartDate, stockHistoryRowKey } from "@/utils/wbStockHistoryRows";
import HistoryQtyCells from "./HistoryQtyCells.vue";
import ProductThumb from "./ProductThumb.vue";

// rows — уже сгруппированные номенклатуры с sizes[] (см. groupStockHistoryRowsByNomenclature).
const props = defineProps({
    dates: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    selectedKeys: { type: Object, default: () => ({}) },
    maxHeight: { type: String, default: "min(70vh, 56rem)" },
    fillHeight: { type: Boolean, default: false },
});

const emit = defineEmits(["toggle", "toggle-page"]);

const expanded = ref({});

const latestDate = computed(() => {
    const dates = Array.isArray(props.dates) ? props.dates : [];
    return dates.length ? dates[dates.length - 1] : null;
});

const headerDates = computed(() => [...(props.dates || [])].reverse());

const allPageSelected = computed(() => (
    props.rows.length > 0 && props.rows.every((row) => Boolean(props.selectedKeys[stockHistoryRowKey(row)]))
));

function isSelected(row) {
    return Boolean(props.selectedKeys[stockHistoryRowKey(row)]);
}

function stickyClass(row) {
    return isSelected(row) ? "bg-primary/5 group-hover:bg-primary/10" : "bg-card group-hover:bg-muted";
}

function isOpen(nmId) {
    return Boolean(expanded.value[nmId]);
}

function toggleExpand(nmId) {
    expanded.value = {
        ...expanded.value,
        [nmId]: !expanded.value[nmId],
    };
}

function sizeLabel(row) {
    return row.tech_size ? String(row.tech_size) : "—";
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
                <template v-for="group in rows" :key="stockHistoryRowKey(group)">
                    <tr
                        class="group cursor-pointer border-b"
                        :class="isSelected(group) ? 'bg-primary/5' : 'hover:bg-accent/40'"
                        @click="emit('toggle', group)"
                    >
                        <td class="sticky left-0 z-20 w-10 px-2 py-2" :class="stickyClass(group)" @click.stop>
                            <Checkbox
                                :model-value="isSelected(group)"
                                @update:model-value="emit('toggle', group)"
                            />
                        </td>
                        <td class="sticky left-10 z-10 w-14 px-2 py-2" :class="stickyClass(group)">
                            <ProductThumb :src="group.image_url" />
                        </td>
                        <td class="sticky left-24 z-10 min-w-[9rem] max-w-[14rem] px-3 py-2" :class="stickyClass(group)">
                            <div class="flex min-w-0 items-center gap-1.5">
                                <button
                                    type="button"
                                    class="shrink-0 rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                                    :aria-expanded="isOpen(group.nm_id)"
                                    :aria-label="isOpen(group.nm_id) ? 'Свернуть размеры' : 'Показать размеры'"
                                    @click.stop="toggleExpand(group.nm_id)"
                                >
                                    <ChevronDown v-if="isOpen(group.nm_id)" class="h-4 w-4" />
                                    <ChevronRight v-else class="h-4 w-4" />
                                </button>
                                <p class="truncate font-medium">{{ group.subject || "—" }}</p>
                            </div>
                        </td>
                        <td class="min-w-[8rem] px-3 py-2">
                            <p class="truncate">{{ group.vendor_code || "—" }}</p>
                        </td>
                        <td class="min-w-[7rem] px-3 py-2 tabular-nums">{{ group.nm_id || "—" }}</td>
                        <td class="min-w-[5rem] px-3 py-2 text-muted-foreground">—</td>
                        <td class="min-w-[6.5rem] px-2 py-2 text-center tabular-nums font-medium">{{ group.quantity ?? 0 }}</td>
                        <td class="min-w-[6.5rem] px-2 py-2 text-center tabular-nums font-medium">{{ group.in_way_to_client ?? 0 }}</td>
                        <td class="min-w-[6.5rem] px-2 py-2 text-center tabular-nums font-medium">{{ group.in_way_from_client ?? 0 }}</td>
                        <HistoryQtyCells
                            :dates="dates"
                            :values="group.series || []"
                            :latest-date="latestDate"
                        />
                    </tr>
                    <tr
                        v-for="size in (isOpen(group.nm_id) ? (group.sizes || []) : [])"
                        :key="stockHistoryRowKey(size)"
                        class="group cursor-pointer border-b bg-background/60"
                        :class="isSelected(size) ? 'bg-primary/5' : 'hover:bg-accent/30'"
                        @click="emit('toggle', size)"
                    >
                        <td class="sticky left-0 z-20 w-10 px-2 py-2" :class="stickyClass(size)" @click.stop>
                            <Checkbox
                                :model-value="isSelected(size)"
                                @update:model-value="emit('toggle', size)"
                            />
                        </td>
                        <td class="sticky left-10 z-10 w-14 px-2 py-2" :class="stickyClass(size)">
                            <ProductThumb :src="size.image_url" />
                        </td>
                        <td class="sticky left-24 z-10 min-w-[9rem] max-w-[14rem] px-3 py-2 pl-9" :class="stickyClass(size)">
                            <p class="truncate text-sm">{{ sizeLabel(size) }}</p>
                        </td>
                        <td class="min-w-[8rem] px-3 py-2">
                            <p class="truncate">{{ size.vendor_code || "—" }}</p>
                        </td>
                        <td class="min-w-[7rem] px-3 py-2 tabular-nums">{{ size.nm_id || "—" }}</td>
                        <td class="min-w-[5rem] px-3 py-2">{{ sizeLabel(size) }}</td>
                        <td class="min-w-[6.5rem] px-2 py-2 text-center tabular-nums font-medium">{{ size.quantity ?? 0 }}</td>
                        <td class="min-w-[6.5rem] px-2 py-2 text-center tabular-nums font-medium">{{ size.in_way_to_client ?? 0 }}</td>
                        <td class="min-w-[6.5rem] px-2 py-2 text-center tabular-nums font-medium">{{ size.in_way_from_client ?? 0 }}</td>
                        <HistoryQtyCells
                            :dates="dates"
                            :values="size.series || []"
                            :latest-date="latestDate"
                        />
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
