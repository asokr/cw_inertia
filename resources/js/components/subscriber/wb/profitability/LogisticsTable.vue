<script setup>
import ProfitabilityItemsTable from "@/components/subscriber/wb/profitability/ProfitabilityItemsTable.vue";
import { logisticsColumns } from "@/utils/profitabilityTableColumns";

defineProps({
    items: { type: Array, default: () => [] },
});
</script>

<template>
    <ProfitabilityItemsTable title="Логистика" :items="items" :columns="logisticsColumns" />
</template>