<script setup>
import ProfitabilityItemsTable from "@/components/subscriber/wb/profitability/ProfitabilityItemsTable.vue";
import { salesColumns } from "@/utils/profitabilityTableColumns";

defineProps({
    items: { type: Array, default: () => [] },
});
</script>

<template>
    <ProfitabilityItemsTable title="Продажи" :items="items" :columns="salesColumns" max-height="37.5rem" />
</template>