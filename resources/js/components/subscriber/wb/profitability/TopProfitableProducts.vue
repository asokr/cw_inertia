<script setup>
import TopProductsList from "@/components/subscriber/wb/profitability/TopProductsList.vue";

defineProps({
    products: { type: Array, default: () => [] },
});

const palette = ["#4F46E5", "#10B981", "#A855F7", "#F97316", "#0EA5E9"];
</script>

<template>
    <TopProductsList
        title="Топ 5 маржинальных товаров"
        :products="products"
        :palette="palette"
        empty-message="Нет данных по прибыльности товаров."
    />
</template>