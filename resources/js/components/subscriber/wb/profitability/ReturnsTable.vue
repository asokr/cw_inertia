<script setup>
import ProfitabilityItemsTable from "@/components/subscriber/wb/profitability/ProfitabilityItemsTable.vue";
import { returnsColumns } from "@/utils/profitabilityTableColumns";

defineProps({
    items: { type: Array, default: () => [] },
    lazy: { type: Boolean, default: false },
    itemsUrl: { type: String, default: "" },
});
</script>

<template>
    <ProfitabilityItemsTable
        title="Возвраты"
        group="returns"
        :items="items"
        :columns="returnsColumns"
        :lazy="lazy"
        :items-url="itemsUrl"
    />
</template>
