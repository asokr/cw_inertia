<script setup>
import { ref } from "vue";
import { Head } from "@inertiajs/vue3";
import LogsDialog from "@/components/subscriber/wb/repricer/LogsDialog.vue";
import RepricerSubnav from "@/components/subscriber/wb/repricer/RepricerSubnav.vue";
import StockFormDialog from "@/components/subscriber/wb/repricer/Stocks/StockFormDialog.vue";
import StocksTable from "@/components/subscriber/wb/repricer/Stocks/StocksTable.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Alert from "@/components/ui/Alert.vue";
import Button from "@/components/ui/Button.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";

const props = defineProps({
    cabinet: { type: Object, required: true },
    stocks: { type: Array, default: () => [] },
    stocksError: { type: String, default: null },
    limits: { type: Object, default: () => ({}) },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Репрайсер", href: "/panel/wb/repricer" },
    { label: props.cabinet.name, href: `/panel/wb/repricer/cabinets/${props.cabinet.id}` },
    { label: "От остатков" },
];

const baseUrl = `/panel/wb/repricer/cabinets/${props.cabinet.id}`;

const addOpen = ref(false);
const editOpen = ref(false);
const selectedStock = ref(null);
const logsOpen = ref(false);
const logsNmId = ref(null);

function openEdit(stock) {
    selectedStock.value = stock;
    editOpen.value = true;
}

function openLogs(nmId) {
    logsNmId.value = nmId;
    logsOpen.value = true;
}
</script>

<template>
    <Head :title="`Репрайсер — ${cabinet.name} — От остатков`" />

    <SubscriberLayout :title="cabinet.name" :breadcrumbs="breadcrumbs">
        <ToolPageHeader
            title="Стратегия от остатков"
            :description="limits.repricer_nmid !== null ? `Осталось номенклатур: ${limits.repricer_nmid}` : ''"
        >
            <template #actions>
                <Button @click="addOpen = true">Добавить номенклатуру</Button>
            </template>
        </ToolPageHeader>

        <RepricerSubnav :cabinet-id="cabinet.id" />

        <div class="mt-4 space-y-4">
            <Alert v-if="stocksError" variant="destructive">{{ stocksError }}</Alert>

            <StocksTable
                :items="stocks"
                :cabinet-id="cabinet.id"
                @edit="openEdit"
                @open-logs="openLogs"
            />
        </div>

        <StockFormDialog
            v-model:open="addOpen"
            :cabinet-id="cabinet.id"
            :store-url="`${baseUrl}/stocks`"
            :sizes-url="`${baseUrl}/stocks/sizes`"
        />

        <StockFormDialog
            v-model:open="editOpen"
            :cabinet-id="cabinet.id"
            :stock="selectedStock"
            :store-url="`${baseUrl}/stocks`"
            :update-url="selectedStock ? `${baseUrl}/stocks/${selectedStock.id}` : ''"
            :sizes-url="`${baseUrl}/stocks/sizes`"
        />

        <LogsDialog
            v-model:open="logsOpen"
            :logs-url="`${baseUrl}/logs`"
            :nm-id="logsNmId"
            strategy="STOCKS"
        />
    </SubscriberLayout>
</template>