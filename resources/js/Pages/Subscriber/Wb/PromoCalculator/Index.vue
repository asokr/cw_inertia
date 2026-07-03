<script setup>
import { ref } from "vue";
import { Head, usePage } from "@inertiajs/vue3";
import CabinetSelectStep from "@/components/subscriber/wb/promo-calculator/CabinetSelectStep.vue";
import CalculateStep from "@/components/subscriber/wb/promo-calculator/CalculateStep.vue";
import FileUploadStep from "@/components/subscriber/wb/promo-calculator/FileUploadStep.vue";
import ResultsTable from "@/components/subscriber/wb/promo-calculator/ResultsTable.vue";
import SendToRepricerPanel from "@/components/subscriber/wb/promo-calculator/SendToRepricerPanel.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Alert from "@/components/ui/Alert.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";

const props = defineProps({
    priceCalcCabinets: { type: Array, default: () => [] },
    repricerCabinets: { type: Array, default: () => [] },
    canUseRepricer: { type: Boolean, default: false },
});

const page = usePage();

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Рентабельность акций" },
];

const cabinetId = ref(null);
const filePath = ref("");
const results = ref([]);
const selected = ref([]);
const flashError = ref("");
const flashSuccess = ref("");

function onUploaded(path) {
    filePath.value = path;
    results.value = [];
    selected.value = [];
}

function onCalculated(data) {
    results.value = data;
    flashError.value = "";
}

function onError(message) {
    flashError.value = message;
}

function onRepricerSuccess(message) {
    flashSuccess.value = message;
    flashError.value = "";
}

if (page.props.flash?.error) {
    flashError.value = page.props.flash.error;
}
if (page.props.flash?.success) {
    flashSuccess.value = page.props.flash.success;
}
</script>

<template>
    <Head title="Рентабельность акций" />

    <SubscriberLayout title="Рентабельность акций" :breadcrumbs="breadcrumbs">
        <ToolPageHeader title="Рентабельность акций Wildberries" />

        <div class="space-y-8">
            <Alert>
                <strong>Важно!</strong> Для верного расчёта у вас должны быть актуальные данные в инструменте «Ценообразование».
            </Alert>

            <Alert v-if="flashSuccess" variant="default">{{ flashSuccess }}</Alert>
            <Alert v-if="flashError" variant="destructive">{{ flashError }}</Alert>

            <CabinetSelectStep v-model="cabinetId" :cabinets="priceCalcCabinets" />
            <FileUploadStep @uploaded="onUploaded" />
            <CalculateStep
                :cabinet-id="cabinetId"
                :file-path="filePath"
                @calculated="onCalculated"
                @error="onError"
            />

            <ResultsTable
                :items="results"
                @update:selected="selected = $event"
                @error="onError"
            />

            <SendToRepricerPanel
                v-if="results.length"
                :selected="selected"
                :repricer-cabinets="repricerCabinets"
                :can-use-repricer="canUseRepricer"
                @success="onRepricerSuccess"
                @error="onError"
            />
        </div>
    </SubscriberLayout>
</template>