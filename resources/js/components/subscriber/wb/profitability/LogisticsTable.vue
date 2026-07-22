<script setup>
import ProfitabilityItemsTable from "@/components/subscriber/wb/profitability/ProfitabilityItemsTable.vue";
import { logisticsColumns } from "@/utils/profitabilityTableColumns";

defineProps({
    items: { type: Array, default: () => [] },
    lazy: { type: Boolean, default: false },
    itemsUrl: { type: String, default: "" },
});
</script>

<template>
    <ProfitabilityItemsTable
        title="Логистика"
        group="logistics"
        :items="items"
        :columns="logisticsColumns"
        :lazy="lazy"
        :items-url="itemsUrl"
    />
</template>
