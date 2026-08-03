<script setup>
import { computed, ref, watch } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import ExperimentSelectStep from "@/components/subscriber/wb/ab-testing/ExperimentSelectStep.vue";
import ExperimentWorkspace from "@/components/subscriber/wb/ab-testing/ExperimentWorkspace.vue";
import ProductSelectStep from "@/components/subscriber/wb/ab-testing/ProductSelectStep.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Button from "@/components/ui/Button.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useFlashToast } from "@/composables/useFlashToast";
import { useAbExperimentPoll } from "@/composables/useAbExperimentPoll";

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
    selectedProduct: {
        type: Object,
        default: null,
    },
    selectedExperiment: {
        type: Object,
        default: null,
    },
    experiments: {
        type: Array,
        default: () => [],
    },
    createdExperiment: {
        type: Object,
        default: null,
    },
});

useFlashToast();

const page = usePage();
const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "A/B-тестирование" },
];

const baseUrl = "/panel/wb/ab-testing";

const selectedProductLocal = ref(props.selectedProduct ?? null);
const selectedExperimentLocal = ref(props.selectedExperiment ?? null);
const loadingExperiments = ref(false);

const experimentsLocal = computed(() => props.experiments ?? []);

/**
 * products | experiments | workspace
 */
const view = computed(() => {
    if (selectedExperimentLocal.value?.id) {
        return "workspace";
    }
    if (selectedProductLocal.value?.id) {
        return "experiments";
    }
    return "products";
});

useAbExperimentPoll({
    shouldPoll: () => view.value === "workspace",
});

watch(
    () => props.selectedProduct,
    (value) => {
        if (value) {
            selectedProductLocal.value = value;
        }
    },
);

watch(
    () => props.selectedExperiment,
    (value) => {
        selectedExperimentLocal.value = value ?? null;
    },
);

function loadExperimentsForProduct(product) {
    if (!product?.id) {
        return;
    }

    loadingExperiments.value = true;
    router.get(
        baseUrl,
        { product_id: product.id },
        {
            only: ["selectedProduct", "selectedExperiment", "experiments", "filters"],
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                loadingExperiments.value = false;
            },
        },
    );
}

function onProductSelect(product) {
    if (!product?.id) {
        return;
    }

    selectedProductLocal.value = product;
    selectedExperimentLocal.value = null;
    loadExperimentsForProduct(product);
}

function openExperiment(experiment) {
    if (!experiment?.id) {
        return;
    }

    selectedExperimentLocal.value = experiment;
    router.get(
        baseUrl,
        {
            product_id: selectedProductLocal.value?.id || experiment.ab_product_id,
            experiment_id: experiment.id,
        },
        {
            only: ["selectedProduct", "selectedExperiment", "experiments", "filters"],
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function onExperimentCreated(experiment) {
    openExperiment(experiment);
}

function onExperimentUpdated(updated) {
    if (!updated?.id) {
        return;
    }
    selectedExperimentLocal.value = {
        ...(selectedExperimentLocal.value ?? {}),
        ...updated,
    };
}

function onCampaignDeleted(updated) {
    onExperimentUpdated(updated);
}

function backToExperiments() {
    selectedExperimentLocal.value = null;
    const productId = selectedProductLocal.value?.id;
    if (productId) {
        router.get(
            baseUrl,
            { product_id: productId },
            {
                only: ["selectedProduct", "selectedExperiment", "experiments", "filters"],
                preserveState: true,
                preserveScroll: true,
            },
        );
    }
}

function backToProducts() {
    selectedExperimentLocal.value = null;
    selectedProductLocal.value = null;
    router.get(baseUrl, {}, {
        only: ["products", "productsMeta", "filters", "selectedProduct", "selectedExperiment", "experiments"],
        preserveState: false,
        preserveScroll: true,
    });
}

watch(
    () => page.props.flash?.created_experiment,
    (created) => {
        if (!created?.id) {
            return;
        }
        openExperiment(created);
    },
    { immediate: true },
);
</script>

<template>
    <Head title="A/B-тестирование" />

    <SubscriberLayout title="A/B-тестирование" :breadcrumbs="breadcrumbs">
        <ToolPageHeader
            title="A/B-тестирование"
            :description="`Кабинет: ${cabinet.name}`"
        />

        <div class="min-w-0 space-y-5">
            <ProductSelectStep
                v-if="view === 'products'"
                :products="products"
                :products-meta="productsMeta"
                :filters="filters"
                :show-url="baseUrl"
                :sync-url="`${baseUrl}/sync`"
                @select="onProductSelect"
            />

            <template v-else-if="view === 'experiments'">
                <ExperimentSelectStep
                    :product="selectedProduct || selectedProductLocal"
                    :experiments="experimentsLocal"
                    :create-url="`${baseUrl}/experiments`"
                    :loading="loadingExperiments"
                    @open="openExperiment"
                    @created="onExperimentCreated"
                />
                <div class="flex border-t border-border/60 pt-4">
                    <Button variant="outline" @click="backToProducts">
                        К списку товаров
                    </Button>
                </div>
            </template>

            <ExperimentWorkspace
                v-else-if="view === 'workspace'"
                :product="selectedProduct || selectedProductLocal"
                :experiment="selectedExperimentLocal"
                :base-url="baseUrl"
                @experiment-updated="onExperimentUpdated"
                @campaign-deleted="onCampaignDeleted"
                @back="backToExperiments"
            />
        </div>
    </SubscriberLayout>
</template>
