<script setup>
import { computed } from "vue";
import ProductCard from "./ProductCard.vue";

const props = defineProps({
    dates: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    expanded: { type: Object, default: () => ({}) },
    details: { type: Object, default: () => ({}) },
    loadingSku: { type: Object, default: () => ({}) },
    maxHeight: { type: String, default: "min(70vh, 56rem)" },
    fillHeight: { type: Boolean, default: false },
});

const emit = defineEmits(["toggle"]);

const latestDate = computed(() => {
    const dates = Array.isArray(props.dates) ? props.dates : [];
    return dates.length ? dates[dates.length - 1] : null;
});

const headerDates = computed(() => [...(props.dates || [])].reverse());

function formatDate(date) {
    const parts = String(date).split("-");
    if (parts.length !== 3) {
        return date;
    }

    return `${parts[2]}.${parts[1]}`;
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
                    <th
                        scope="col"
                        class="sticky left-0 top-0 z-30 min-w-[16rem] bg-card px-3 py-2 text-left font-medium"
                    >
                        Товар
                    </th>
                    <th
                        v-for="date in headerDates"
                        :key="date"
                        scope="col"
                        class="sticky top-0 z-20 min-w-[3.25rem] border-l px-1.5 py-2 text-center font-medium tabular-nums"
                        :class="date === latestDate ? 'bg-muted/50' : 'bg-card'"
                    >
                        {{ formatDate(date) }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <ProductCard
                    v-for="product in products"
                    :key="product.sku"
                    :product="product"
                    :dates="dates"
                    :latest-date="latestDate"
                    :expanded="Boolean(expanded[product.sku])"
                    :detail="details[product.sku]"
                    :loading="Boolean(loadingSku[product.sku])"
                    @toggle="emit('toggle', product.sku)"
                />
            </tbody>
        </table>
    </div>
</template>
