<script setup>
import { ref } from "vue";
import { Head } from "@inertiajs/vue3";
import LogsDialog from "@/components/subscriber/wb/repricer/LogsDialog.vue";
import RepricerSubnav from "@/components/subscriber/wb/repricer/RepricerSubnav.vue";
import SettingFormDialog from "@/components/subscriber/wb/repricer/Time/SettingFormDialog.vue";
import SettingsTable from "@/components/subscriber/wb/repricer/Time/SettingsTable.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Alert from "@/components/ui/Alert.vue";
import Button from "@/components/ui/Button.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";

const props = defineProps({
    cabinet: { type: Object, required: true },
    settings: { type: Array, default: () => [] },
    settingsError: { type: String, default: null },
    limits: { type: Object, default: () => ({}) },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Репрайсер", href: "/panel/wb/repricer" },
    { label: props.cabinet.name, href: `/panel/wb/repricer/cabinets/${props.cabinet.id}` },
    { label: "По времени" },
];

const baseUrl = `/panel/wb/repricer/cabinets/${props.cabinet.id}`;

const addOpen = ref(false);
const editOpen = ref(false);
const selectedSetting = ref(null);
const logsOpen = ref(false);
const logsNmId = ref(null);

function openEdit(setting) {
    selectedSetting.value = setting;
    editOpen.value = true;
}

function openLogs(nmId) {
    logsNmId.value = nmId;
    logsOpen.value = true;
}
</script>

<template>
    <Head :title="`Репрайсер — ${cabinet.name} — По времени`" />

    <SubscriberLayout :title="cabinet.name" :breadcrumbs="breadcrumbs">
        <ToolPageHeader
            title="Стратегия по времени"
            :description="limits.repricer_nmid !== null ? `Осталось номенклатур: ${limits.repricer_nmid}` : ''"
        >
            <template #actions>
                <Button @click="addOpen = true">Добавить номенклатуру</Button>
            </template>
        </ToolPageHeader>

        <RepricerSubnav :cabinet-id="cabinet.id" />

        <div class="mt-4 space-y-4">
            <Alert v-if="settingsError" variant="destructive">{{ settingsError }}</Alert>

            <SettingsTable
                :items="settings"
                :cabinet-id="cabinet.id"
                :logs-url="`${baseUrl}/logs`"
                @edit="openEdit"
                @open-logs="openLogs"
            />
        </div>

        <SettingFormDialog
            v-model:open="addOpen"
            :cabinet-id="cabinet.id"
            :store-url="`${baseUrl}/time`"
        />

        <SettingFormDialog
            v-model:open="editOpen"
            :cabinet-id="cabinet.id"
            :setting="selectedSetting"
            :store-url="`${baseUrl}/time`"
            :update-url="selectedSetting ? `${baseUrl}/time/${selectedSetting.id}` : ''"
        />

        <LogsDialog
            v-model:open="logsOpen"
            :logs-url="`${baseUrl}/logs`"
            :nm-id="logsNmId"
            strategy="TIME"
        />
    </SubscriberLayout>
</template>