<script setup>
import ProfitabilityItemsTable from "@/components/subscriber/wb/profitability/ProfitabilityItemsTable.vue";
import { otherOperationsColumns } from "@/utils/profitabilityTableColumns";

defineProps({
    items: { type: Array, default: () => [] },
});
</script>

<template>
    <ProfitabilityItemsTable title="Доп.расходы и доплаты" :items="items" :columns="otherOperationsColumns" />
</template>