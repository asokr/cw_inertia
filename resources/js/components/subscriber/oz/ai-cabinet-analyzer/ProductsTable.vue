<script setup>
import { computed, h, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import EditableDataTable from "@/components/subscriber/tools/EditableDataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";

const props = defineProps({
    showUrl: { type: String, required: true },
    reportId: { type: Number, default: null },
    items: { type: Array, default: () => [] },
    meta: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const productIdInput = ref(props.filters.product_id ?? "");
const offerIdInput = ref(props.filters.offer_id ?? "");
const qInput = ref(props.filters.q ?? "");

const columns = computed(() => [
    {
        accessorKey: "primary_image",
        header: "Фото",
        enableSorting: false,
        meta: { isImage: true },
        cell: ({ row }) => {
            const src = row.original.primary_image;
            if (src) {
                return h("img", {
                    src,
                    alt: `Product ${row.original.product_id ?? ""}`,
                    class: "h-10 w-10 rounded object-cover",
                    loading: "lazy",
                });
            }

            return h(
                "div",
                { class: "flex h-10 w-10 items-center justify-center rounded bg-muted text-xs text-muted-foreground" },
                "—",
            );
        },
    },
    {
        accessorKey: "product_id",
        header: "Product ID",
        enableSorting: false,
        cell: ({ row }) => row.original.product_id ?? "—",
    },
    {
        accessorKey: "offer_id",
        header: "Offer ID",
        enableSorting: false,
        cell: ({ row }) => row.original.offer_id ?? "—",
    },
    {
        accessorKey: "sku",
        header: "SKU",
        enableSorting: false,
        cell: ({ row }) => row.original.sku ?? "—",
    },
    {
        accessorKey: "name",
        header: "Название",
        enableSorting: false,
        cell: ({ row }) => row.original.name ?? "—",
    },
    {
        accessorKey: "brand",
        header: "Бренд",
        enableSorting: false,
        cell: ({ row }) => row.original.brand ?? "—",
    },
    {
        accessorKey: "price",
        header: "Цена",
        enableSorting: false,
        cell: ({ row }) => row.original.price ?? "—",
    },
    {
        accessorKey: "price_index",
        header: "Индекс цен",
        enableSorting: false,
        cell: ({ row }) => row.original.price_indexes?.color_index ?? "—",
    },
    {
        accessorKey: "content_rating",
        header: "Контент",
        enableSorting: false,
        cell: ({ row }) => {
            const v = row.original.content_rating?.rating;
            return v === undefined || v === null ? "—" : String(v);
        },
    },
    {
        accessorKey: "analytics_revenue",
        header: "Выручка (зак.)",
        enableSorting: false,
        cell: ({ row }) => {
            const v = row.original.analytics?.revenue;
            return v === undefined || v === null ? "—" : String(v);
        },
    },
    {
        accessorKey: "analytics_ordered_units",
        header: "Заказы, шт",
        enableSorting: false,
        cell: ({ row }) => {
            const v = row.original.analytics?.ordered_units;
            return v === undefined || v === null ? "—" : String(v);
        },
    },
    {
        accessorKey: "ads_spend",
        header: "Рекл. расход",
        enableSorting: false,
        cell: ({ row }) => {
            const v = row.original.advertising?.spend;
            return v === undefined || v === null ? "—" : String(v);
        },
    },
    {
        accessorKey: "ads_orders",
        header: "Рекл. заказы",
        enableSorting: false,
        cell: ({ row }) => {
            const v = row.original.advertising?.orders;
            return v === undefined || v === null ? "—" : String(v);
        },
    },
    {
        accessorKey: "stocks_free",
        header: "Остаток",
        enableSorting: false,
        cell: ({ row }) => {
            const v = row.original.stocks?.free_to_sell;
            return v === undefined || v === null ? "—" : String(v);
        },
    },
    {
        accessorKey: "liquidity",
        header: "Ликвидность",
        enableSorting: false,
        cell: ({ row }) => row.original.liquidity?.turnover_grade
            || row.original.turnover?.turnover_grade
            || "—",
    },
    {
        accessorKey: "top_query",
        header: "Топ запрос",
        enableSorting: false,
        cell: ({ row }) => {
            const queries = Array.isArray(row.original.search?.queries)
                ? row.original.search.queries
                : [];
            return queries[0]?.query || "—";
        },
    },
    {
        accessorKey: "visible",
        header: "Видимый",
        enableSorting: false,
        cell: ({ row }) => {
            if (row.original.visible === true) return "Да";
            if (row.original.visible === false) return "Нет";
            return "—";
        },
    },
    {
        accessorKey: "is_archived",
        header: "Архив",
        enableSorting: false,
        cell: ({ row }) => {
            if (row.original.is_archived === true) return "Да";
            if (row.original.is_archived === false) return "Нет";
            return "—";
        },
    },
]);

let searchTimeout;
watch([productIdInput, offerIdInput, qInput], () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => reload({ page: 1 }), 500);
});

function reload(overrides = {}) {
    router.get(props.showUrl, {
        report_id: props.reportId,
        page: overrides.page ?? props.filters.page ?? 1,
        per_page: overrides.per_page ?? props.filters.per_page ?? 15,
        product_id: productIdInput.value,
        offer_id: offerIdInput.value,
        q: qInput.value,
    }, {
        only: ["products", "productsMeta", "productFilters"],
        preserveState: true,
        preserveScroll: true,
    });
}

const hasFilters = computed(() => Boolean(
    String(productIdInput.value).trim()
    || String(offerIdInput.value).trim()
    || String(qInput.value).trim(),
));
</script>

<template>
    <div class="space-y-4 rounded-lg border p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="space-y-1">
                <Label for="product_id">Product ID</Label>
                <Input id="product_id" v-model="productIdInput" placeholder="ID" class="w-40" />
            </div>
            <div class="space-y-1">
                <Label for="offer_id">Offer ID</Label>
                <Input id="offer_id" v-model="offerIdInput" placeholder="Артикул" class="w-48" />
            </div>
            <div class="space-y-1">
                <Label for="q">Поиск</Label>
                <Input id="q" v-model="qInput" placeholder="Название, SKU…" class="w-56" />
            </div>
            <Button
                variant="outline"
                size="sm"
                :disabled="!hasFilters"
                @click="() => { productIdInput = ''; offerIdInput = ''; qInput = ''; reload({ page: 1 }); }"
            >
                Сбросить
            </Button>
            <Button variant="outline" size="sm" @click="reload()">Обновить</Button>
        </div>

        <EditableDataTable
            :columns="columns"
            :data="items"
            max-height="32rem"
            empty-text="В отчёте нет товаров."
        />

        <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
            <span>
                {{ meta.total ? `${((meta.current_page - 1) * meta.per_page) + 1}-${Math.min(meta.current_page * meta.per_page, meta.total)} из ${meta.total}` : "0 записей" }}
            </span>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="meta.current_page <= 1"
                    @click="reload({ page: meta.current_page - 1 })"
                >
                    Назад
                </Button>
                <span>стр. {{ meta.current_page || 1 }} / {{ meta.last_page || 1 }}</span>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="(meta.current_page || 1) >= (meta.last_page || 1)"
                    @click="reload({ page: meta.current_page + 1 })"
                >
                    Вперёд
                </Button>
            </div>
        </div>
    </div>
</template>
