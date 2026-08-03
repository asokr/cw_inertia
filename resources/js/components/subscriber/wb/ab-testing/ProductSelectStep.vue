<script setup>
import { ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { RefreshCw } from "lucide-vue-next";
import ProductsTable from "./ProductsTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
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
    showUrl: {
        type: String,
        required: true,
    },
    syncUrl: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(["select"]);

const { showError } = useFlashToast();
const searchInput = ref(props.filters.search ?? "");
const syncing = ref(false);
const perPageOptions = [25, 50, 100];

let searchTimeout;
watch(searchInput, (value) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => reload({ search: value || "", page: 1 }), 400);
});

function reload(overrides = {}) {
    router.get(
        props.showUrl,
        {
            page: overrides.page ?? props.filters.page ?? 1,
            per_page: overrides.per_page ?? props.filters.per_page ?? 25,
            search: overrides.search ?? props.filters.search ?? "",
        },
        {
            only: ["products", "productsMeta", "filters"],
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function syncProducts() {
    syncing.value = true;
    router.post(
        props.syncUrl,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                syncing.value = false;
            },
            onError: () => {
                showError("Не удалось обновить список товаров");
            },
        },
    );
}

function changePage(page) {
    reload({ page });
}

function changePerPage(perPage) {
    reload({ per_page: perPage, page: 1 });
}

function onSelect(product) {
    emit("select", product);
}
</script>

<template>
    <div class="space-y-4">
        <div class="space-y-1">
            <h3 class="text-lg font-semibold">Выбор товара</h3>
            <p class="text-sm text-muted-foreground">
                Нажмите на товар, чтобы открыть список его экспериментов.
            </p>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <Input
                v-model="searchInput"
                class="max-w-md flex-1"
                placeholder="Артикул WB или артикул продавца"
            />
            <div class="flex flex-wrap items-center gap-2">
                <span v-if="productsMeta.total > 0" class="text-sm text-muted-foreground">
                    Позиций: {{ productsMeta.total }}
                </span>
                <Button variant="outline" size="sm" :disabled="syncing" @click="syncProducts">
                    <RefreshCw class="mr-1.5 h-4 w-4" :class="{ 'animate-spin': syncing }" />
                    Обновить список товаров
                </Button>
            </div>
        </div>

        <ProductsTable
            :items="products"
            @select="onSelect"
        />

        <div
            v-if="productsMeta.total > 0"
            class="flex flex-wrap items-center justify-between gap-3 text-sm"
        >
            <div class="flex items-center gap-2">
                <span class="text-muted-foreground">На странице:</span>
                <select
                    class="rounded-md border bg-background px-2 py-1"
                    :value="productsMeta.per_page"
                    @change="changePerPage(Number($event.target.value))"
                >
                    <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
                </select>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="(productsMeta.current_page ?? 1) <= 1"
                    @click="changePage((productsMeta.current_page ?? 1) - 1)"
                >
                    Назад
                </Button>
                <span>
                    {{ productsMeta.current_page ?? 1 }} / {{ productsMeta.last_page || 1 }}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="(productsMeta.current_page ?? 1) >= (productsMeta.last_page || 1)"
                    @click="changePage((productsMeta.current_page ?? 1) + 1)"
                >
                    Вперёд
                </Button>
            </div>
        </div>
    </div>
</template>
