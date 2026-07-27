<script setup>
import { computed, ref } from "vue";
import { Head } from "@inertiajs/vue3";
import AbTestingWizardSteps from "@/components/subscriber/wb/ab-testing/AbTestingWizardSteps.vue";
import ProductSelectStep from "@/components/subscriber/wb/ab-testing/ProductSelectStep.vue";
import StepPlaceholder from "@/components/subscriber/wb/ab-testing/StepPlaceholder.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Button from "@/components/ui/Button.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    cabinet: {
        type: Object,
        required: true,
    },
    products: {
        type: Array,
        default: () => [],
    },
    productsMeta: {
        type: Object,
        default: () => ({}),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

useFlashToast();

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "A/B-тестирование" },
];

const steps = [
    { id: 1, title: "Выбор товара", description: "Номенклатура кабинета" },
    { id: 2, title: "Фотографии", description: "Варианты для теста" },
    { id: 3, title: "Настройки", description: "Параметры эксперимента" },
    { id: 4, title: "Создание рекламы", description: "Кампания WB" },
    { id: 5, title: "Тестирование", description: "Сбор статистики" },
    { id: 6, title: "Результаты", description: "Победитель и аналитика" },
];

const currentStep = ref(1);
const selectedProduct = ref(null);

const baseUrl = "/panel/wb/ab-testing";

const currentStepMeta = computed(
    () => steps.find((step) => step.id === currentStep.value) ?? steps[0],
);

const canContinue = computed(() => {
    if (currentStep.value === 1) {
        return Boolean(selectedProduct.value?.nm_id);
    }

    return false;
});

function goNext() {
    if (currentStep.value === 1 && selectedProduct.value) {
        currentStep.value = 2;
    }
}

function goBack() {
    if (currentStep.value > 1) {
        currentStep.value -= 1;
    }
}
</script>

<template>
    <Head title="A/B-тестирование" />

    <SubscriberLayout title="A/B-тестирование" :breadcrumbs="breadcrumbs">
        <ToolPageHeader
            title="A/B-тестирование"
            :description="`Кабинет: ${cabinet.name}`"
        />

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_17rem] xl:grid-cols-[minmax(0,1fr)_18.5rem]">
            <div class="min-w-0 space-y-5">
                <ProductSelectStep
                    v-if="currentStep === 1"
                    v-model:selected-product="selectedProduct"
                    :products="products"
                    :products-meta="productsMeta"
                    :filters="filters"
                    :show-url="baseUrl"
                    :sync-url="`${baseUrl}/sync`"
                />

                <StepPlaceholder
                    v-else
                    :title="currentStepMeta.title"
                    :description="
                        currentStep === 2
                            ? 'На следующем этапе здесь появится загрузка фотографий для эксперимента. Сейчас шаг-заглушка.'
                            : 'Этот шаг будет реализован позже.'
                    "
                />

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border/60 pt-4">
                    <div class="text-sm text-muted-foreground">
                        <template v-if="currentStep === 1 && selectedProduct">
                            Выбран:
                            <span class="font-medium text-foreground">
                                {{ selectedProduct.title || selectedProduct.nm_id }}
                            </span>
                            <span class="text-muted-foreground">
                                · {{ selectedProduct.nm_id }}
                            </span>
                        </template>
                        <template v-else-if="currentStep === 1">
                            Выберите товар, чтобы продолжить
                        </template>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button
                            v-if="currentStep > 1"
                            variant="outline"
                            @click="goBack"
                        >
                            Назад
                        </Button>
                        <Button
                            v-if="currentStep < steps.length"
                            :disabled="!canContinue"
                            @click="goNext"
                        >
                            Продолжить
                        </Button>
                    </div>
                </div>
            </div>

            <div class="lg:sticky lg:top-4 lg:self-start">
                <AbTestingWizardSteps :steps="steps" :current-step="currentStep" />
            </div>
        </div>
    </SubscriberLayout>
</template>
