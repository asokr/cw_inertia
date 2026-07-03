<script setup>
import ProfitabilityItemsTable from "@/components/subscriber/wb/profitability/ProfitabilityItemsTable.vue";
import { returnsColumns } from "@/utils/profitabilityTableColumns";

defineProps({
    items: { type: Array, default: () => [] },
});
</script>

<template>
    <ProfitabilityItemsTable title="Возвраты" :items="items" :columns="returnsColumns" />
</template>