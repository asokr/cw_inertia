<script setup>
import TopProductsList from "@/components/subscriber/wb/profitability/TopProductsList.vue";

defineProps({
    products: { type: Array, default: () => [] },
});

const palette = ["#F97316", "#EF4444", "#F59E0B", "#DC2626", "#FB7185"];
</script>

<template>
    <TopProductsList
        title="Топ 5 низкомаржинальных товаров"
        :products="products"
        :palette="palette"
        empty-message="Нет данных по низкой маржинальности."
    />
</template>