<script setup>
import ProfitabilityItemsTable from "@/components/subscriber/wb/profitability/ProfitabilityItemsTable.vue";
import { salesColumns } from "@/utils/profitabilityTableColumns";

defineProps({
    items: { type: Array, default: () => [] },
    lazy: { type: Boolean, default: false },
    itemsUrl: { type: String, default: "" },
});
</script>

<template>
    <ProfitabilityItemsTable
        title="Продажи"
        group="sales"
        :items="items"
        :columns="salesColumns"
        :lazy="lazy"
        :items-url="itemsUrl"
        max-height="37.5rem"
    />
</template>
