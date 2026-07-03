<script setup>
import { ref } from "vue";
import { Head } from "@inertiajs/vue3";
import CabinetToolbar from "@/components/subscriber/wb/price-calc/CabinetToolbar.vue";
import CardsTable from "@/components/subscriber/wb/price-calc/CardsTable.vue";
import SettingsDialog from "@/components/subscriber/wb/price-calc/SettingsDialog.vue";
import WorkflowAlert from "@/components/subscriber/wb/price-calc/WorkflowAlert.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Alert from "@/components/ui/Alert.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";

const props = defineProps({
    cabinet: { type: Object, required: true },
    settings: { type: Object, default: null },
    cards: { type: Array, default: () => [] },
    cardsMeta: { type: Object, default: () => ({}) },
    cardsError: { type: String, default: null },
    filters: { type: Object, default: () => ({}) },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Ценообразование", href: "/panel/wb/price-calc" },
    { label: props.cabinet.name },
];

const settingsOpen = ref(false);

const baseUrl = `/panel/wb/price-calc/cabinets/${props.cabinet.id}`;
</script>

<template>
    <Head :title="`Ценообразование — ${cabinet.name}`" />

    <SubscriberLayout :title="cabinet.name" :breadcrumbs="breadcrumbs">
        <ToolPageHeader title="Ценообразование" :description="cabinet.name" />

        <div class="space-y-4">
            <CabinetToolbar
                :cabinet="cabinet"
                :cards-meta="cardsMeta"
                :sync-url="`${baseUrl}/sync`"
                :import-volume-url="`${baseUrl}/import-volume`"
                :import-excel-url="`${baseUrl}/import-excel`"
                :export-excel-url="`${baseUrl}/export-excel`"
                @open-settings="settingsOpen = true"
            />

            <WorkflowAlert />

            <Alert v-if="cardsError" variant="destructive">{{ cardsError }}</Alert>

            <CardsTable
                :items="cards"
                :settings="settings ?? {}"
                :cards-meta="cardsMeta"
                :filters="filters"
                :show-url="baseUrl"
            />
        </div>

        <SettingsDialog
            v-model:open="settingsOpen"
            :settings="settings"
            :save-url="`${baseUrl}/settings`"
        />
    </SubscriberLayout>
</template>