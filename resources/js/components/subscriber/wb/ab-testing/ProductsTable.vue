<script setup>
import { computed, h } from "vue";
import { ImageOff } from "lucide-vue-next";
import EditableDataTable from "@/components/subscriber/tools/EditableDataTable.vue";
import Badge from "@/components/ui/Badge.vue";
import { resolveAbTestStatus } from "./abTestStatus";

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    selectedNmId: {
        type: [Number, String, null],
        default: null,
    },
});

const emit = defineEmits(["select"]);

function formatPrice(value) {
    if (value == null || value === "") {
        return "—";
    }

    const number = Number(value);
    if (Number.isNaN(number)) {
        return "—";
    }

    return `${new Intl.NumberFormat("ru-RU", {
        maximumFractionDigits: 0,
    }).format(number)} ₽`;
}

function formatDate(value) {
    if (!value) {
        return "—";
    }

    try {
        return new Intl.DateTimeFormat("ru-RU", {
            day: "2-digit",
            month: "2-digit",
            year: "numeric",
        }).format(new Date(value));
    } catch {
        return "—";
    }
}

function productTitleCell(row) {
    const photo = row.photo_url;
    const title = row.title || "Без названия";

    return h("div", { class: "flex min-w-[14rem] max-w-sm items-center gap-2.5" }, [
        photo
            ? h("img", {
                src: photo,
                alt: "",
                loading: "lazy",
                referrerpolicy: "no-referrer",
                class: "h-10 w-10 shrink-0 rounded-md border border-border/60 object-cover bg-muted",
            })
            : h(
                "div",
                {
                    class: "flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-dashed border-border bg-muted/50 text-muted-foreground",
                },
                [h(ImageOff, { class: "h-4 w-4" })],
            ),
        h(
            "span",
            {
                class: "line-clamp-2 whitespace-normal text-sm font-medium leading-snug",
                title,
            },
            title,
        ),
    ]);
}

function statusCell(row) {
    const status = resolveAbTestStatus(row.test_status);

    return h(Badge, { variant: status.variant }, () => status.label);
}

const columns = computed(() => [
    {
        id: "select",
        header: "",
        enableSorting: false,
        cell: ({ row }) => {
            const nmId = row.original.nm_id;
            const checked = Number(props.selectedNmId) === Number(nmId);

            return h("input", {
                type: "radio",
                name: "ab-product",
                checked,
                class: "h-4 w-4 accent-primary",
                onClick: (event) => {
                    event.stopPropagation();
                    emit("select", row.original);
                },
                onChange: () => emit("select", row.original),
            });
        },
    },
    {
        id: "title",
        accessorKey: "title",
        header: "Название",
        enableSorting: false,
        cell: ({ row }) => productTitleCell(row.original),
    },
    {
        accessorKey: "nm_id",
        header: "Артикул WB",
        enableSorting: false,
    },
    {
        accessorKey: "vendor_code",
        header: "Артикул продавца",
        enableSorting: false,
        cell: ({ row }) => row.original.vendor_code || "—",
    },
    {
        accessorKey: "brand",
        header: "Бренд",
        enableSorting: false,
        cell: ({ row }) => row.original.brand || "—",
    },
    {
        accessorKey: "subject_name",
        header: "Категория",
        enableSorting: false,
        cell: ({ row }) => row.original.subject_name || "—",
    },
    {
        accessorKey: "price",
        header: "Цена",
        enableSorting: false,
        cell: ({ row }) => formatPrice(row.original.price),
    },
    {
        accessorKey: "rating",
        header: "Рейтинг",
        enableSorting: false,
        cell: ({ row }) => (row.original.rating != null ? row.original.rating : "—"),
    },
    {
        id: "test_status",
        header: "Статус теста",
        enableSorting: false,
        cell: ({ row }) => statusCell(row.original),
    },
    {
        id: "test_created_at",
        header: "Дата создания теста",
        enableSorting: false,
        cell: ({ row }) => formatDate(row.original.test_created_at),
    },
]);

function getRowClass(item) {
    if (Number(props.selectedNmId) === Number(item.nm_id)) {
        return "bg-primary/5 cursor-pointer";
    }

    return "cursor-pointer";
}

function onRowClick(item) {
    emit("select", item);
}
</script>

<template>
    <div
        class="ab-products-table"
        @click="
            (event) => {
                const row = event.target.closest('tbody tr');
                if (!row) return;
                const index = Array.from(row.parentElement.children).indexOf(row);
                if (index >= 0 && items[index]) onRowClick(items[index]);
            }
        "
    >
        <EditableDataTable
            :columns="columns"
            :data="items"
            :get-row-class="getRowClass"
            max-height="calc(100dvh - 22rem)"
            empty-text="Список товаров пуст. Нажмите «Обновить список товаров»."
        />
    </div>
</template>
