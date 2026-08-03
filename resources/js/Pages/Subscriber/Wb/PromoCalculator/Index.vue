<script setup>
import { computed, ref } from "vue";
import { Head } from "@inertiajs/vue3";
import { Building2 } from "lucide-vue-next";
import CalculateStep from "@/components/subscriber/wb/promo-calculator/CalculateStep.vue";
import FileUploadStep from "@/components/subscriber/wb/promo-calculator/FileUploadStep.vue";
import PromoCalculatorFaq from "@/components/subscriber/wb/promo-calculator/PromoCalculatorFaq.vue";
import PromoWizardSteps from "@/components/subscriber/wb/promo-calculator/PromoWizardSteps.vue";
import ResultsTable from "@/components/subscriber/wb/promo-calculator/ResultsTable.vue";
import SendToRepricerPanel from "@/components/subscriber/wb/promo-calculator/SendToRepricerPanel.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Alert from "@/components/ui/Alert.vue";
import Badge from "@/components/ui/Badge.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    cabinet: { type: Object, required: true },
    canUseRepricer: { type: Boolean, default: false },
});

const { showError, showSuccess } = useFlashToast();

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Рентабельность акций" },
];

const filePath = ref("");
const results = ref([]);
const selected = ref([]);

const currentStep = computed(() => {
    if (results.value.length) return 3;
    if (filePath.value) return 2;
    return 1;
});

function onUploaded(path) {
    filePath.value = path;
    results.value = [];
    selected.value = [];
}

function onCalculated(data) {
    results.value = data;
}

function onError(message) {
    showError(message);
}

function onRepricerSuccess(message) {
    showSuccess(message);
}
</script>

<template>
    <Head title="Рентабельность акций" />

    <SubscriberLayout title="Рентабельность акций" :breadcrumbs="breadcrumbs">
        <ToolPageHeader
            title="Рентабельность акций"
            description="Считаем маржу и рентабельность по Excel-отчёту акции WB, используя данные ценообразования активного кабинета."
        >
            <template #actions>
                <Badge variant="secondary" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm">
                    <Building2 class="h-3.5 w-3.5" />
                    {{ cabinet.name }}
                </Badge>
            </template>
        </ToolPageHeader>

        <div class="space-y-6">
            <Alert>
                <strong>Важно!</strong>
                Для верного расчёта нужны актуальные данные в
                <a
                    href="/panel/wb/price-calc"
                    class="font-medium underline underline-offset-2"
                >
                    Ценообразовании
                </a>
                для кабинета «{{ cabinet.name }}».
            </Alert>

            <PromoWizardSteps :current-step="currentStep" />

            <FileUploadStep @uploaded="onUploaded" />

            <CalculateStep
                :file-path="filePath"
                :cabinet-name="cabinet.name"
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
                :cabinet="cabinet"
                :can-use-repricer="canUseRepricer"
                @success="onRepricerSuccess"
                @error="onError"
            />

            <PromoCalculatorFaq />
        </div>
    </SubscriberLayout>
</template>
