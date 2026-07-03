<script setup>
import { computed, ref, watch } from "vue";
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
</script>

<template>
    <div v-if="items.length" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-lg font-semibold">Рассчёт</h3>
            <ExportButton :data="items" @error="emit('error', $event)" />
        </div>

        <Input v-model="search" placeholder="Найти по артикулу или NMID" class="max-w-sm" />

        <div class="overflow-auto rounded-md border" style="max-height: 32rem">
            <table class="w-full text-sm">
                <thead class="sticky top-0 border-b bg-muted/90">
                    <tr>
                        <th class="px-3 py-2 text-left">
                            <Checkbox
                                :model-value="allVisibleSelected"
                                @update:model-value="toggleAll"
                            />
                        </th>
                        <th class="px-3 py-2 text-left">Артикул поставщика</th>
                        <th class="px-3 py-2 text-left">Артикул WB</th>
                        <th class="px-3 py-2 text-left">Плановая цена</th>
                        <th class="px-3 py-2 text-left">Текущая цена</th>
                        <th class="px-3 py-2 text-left">Мин. цена</th>
                        <th class="px-3 py-2 text-left">Скидка</th>
                        <th class="px-3 py-2 text-left">Маржа</th>
                        <th class="px-3 py-2 text-left">Рентабельность</th>
                        <th class="px-3 py-2 text-left">Остаток</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in filteredItems"
                        :key="item.nm_id"
                        class="border-b"
                        :class="rowProfitClass(item.profit)"
                    >
                        <td class="px-3 py-2">
                            <Checkbox
                                :model-value="selectedIds.has(item.nm_id)"
                                @update:model-value="toggleRow(item.nm_id, $event)"
                            />
                        </td>
                        <td class="px-3 py-2">{{ item.vendor_art || "—" }}</td>
                        <td class="px-3 py-2">{{ item.nm_id }}</td>
                        <td class="px-3 py-2">{{ formatRub(item.plan_price) }}</td>
                        <td class="px-3 py-2">{{ formatRub(item.current_price) }}</td>
                        <td class="px-3 py-2">{{ formatRub(item.min_price) }}</td>
                        <td class="px-3 py-2">
                            <template v-if="item.change_discount">
                                <span class="text-xs line-through text-muted-foreground">{{ item.wb_discount }} %</span>
                                <span class="ml-2 font-medium">{{ item.change_discount }} %</span>
                            </template>
                            <template v-else>{{ item.wb_discount }} %</template>
                        </td>
                        <td class="px-3 py-2">{{ formatRub(item.margin) }}</td>
                        <td class="px-3 py-2">{{ formatPercent(item.profit) }}</td>
                        <td class="px-3 py-2">{{ formatCount(item.stock) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>