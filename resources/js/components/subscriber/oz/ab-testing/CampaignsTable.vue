<script setup>
import { computed, h } from "vue";
import EditableDataTable from "@/components/subscriber/tools/EditableDataTable.vue";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";
import { bidTypeLabel, paymentTypeLabel, resolveCampaignStatus } from "./campaignStatus";

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    selectedId: {
        type: [Number, String, null],
        default: null,
    },
    busyAdvertId: {
        type: [Number, String, null],
        default: null,
    },
});

const emit = defineEmits(["select", "pause", "delete", "deposit"]);

function statusCell(row) {
    const status = resolveCampaignStatus(row.status);
    const label = row.status_label || status.label;
    const variant = row.status_variant || status.variant;
    const badges = [h(Badge, { variant }, () => label)];

    if (row.is_busy_by_ab) {
        badges.push(h(Badge, { variant: "warning", class: "ml-1" }, () => "В A/B-тесте"));
    }

    return h("div", { class: "flex flex-wrap items-center gap-1" }, badges);
}

function nmCell(row) {
    const count = Number(row.nm_count) || (row.nm_ids?.length ?? 0);
    const ids = Array.isArray(row.nm_ids) ? row.nm_ids : [];
    const title = ids.length ? ids.join(", ") : "—";

    return h(
        "span",
        { class: "tabular-nums text-sm text-muted-foreground", title },
        count > 0 ? String(count) : "—",
    );
}

function actionButton(label, { variant = "outline", className = "", disabled = false, onClick }) {
    return h(
        Button,
        {
            size: "sm",
            variant,
            class: `h-7 px-2 text-xs ${className}`.trim(),
            disabled,
            onClick: (e) => {
                e.stopPropagation();
                onClick();
            },
        },
        () => label,
    );
}

function actionsCell(row) {
    const busy = Number(props.busyAdvertId) === Number(row.id);
    const nodes = [];

    if (row.can_pause) {
        nodes.push(
            actionButton("Пауза", {
                variant: "outline",
                disabled: busy,
                onClick: () => emit("pause", row),
            }),
        );
    }

    if (row.can_deposit) {
        nodes.push(
            actionButton("Пополнить", {
                variant: "outline",
                disabled: busy,
                onClick: () => emit("deposit", row),
            }),
        );
    }

    if (row.can_delete) {
        nodes.push(
            actionButton("Удалить", {
                variant: "ghost",
                className: "text-destructive hover:text-destructive",
                disabled: busy,
                onClick: () => emit("delete", row),
            }),
        );
    }

    if (!nodes.length) {
        if (row.edit_block_reason) {
            nodes.push(
                h(
                    "span",
                    { class: "text-xs text-muted-foreground", title: row.edit_block_reason },
                    row.edit_block_reason,
                ),
            );
        } else {
            nodes.push(h("span", { class: "text-xs text-muted-foreground" }, "—"));
        }
    }

    return h("div", { class: "flex min-w-[12rem] flex-wrap items-center gap-1.5" }, nodes);
}

const columns = computed(() => [
    {
        accessorKey: "name",
        header: "Название",
        enableSorting: false,
        cell: ({ row }) =>
            h("div", { class: "min-w-[10rem] max-w-[16rem]" }, [
                h(
                    "span",
                    {
                        class: "font-medium text-foreground line-clamp-2",
                        title: row.original.name,
                    },
                    row.original.name || "—",
                ),
                row.original.is_selected
                    ? h(
                          "span",
                          { class: "mt-0.5 block text-xs text-primary" },
                          "Выбрана для эксперимента",
                      )
                    : row.original.contains_product
                      ? h(
                            "span",
                            { class: "mt-0.5 block text-xs text-muted-foreground" },
                            "Товар уже в кампании",
                        )
                      : null,
            ]),
    },
    {
        id: "status",
        header: "Статус",
        enableSorting: false,
        cell: ({ row }) => statusCell(row.original),
    },
    {
        id: "bid_type",
        header: "Тип ставки",
        enableSorting: false,
        cell: ({ row }) =>
            row.original.bid_type_label || bidTypeLabel(row.original.bid_type),
    },
    {
        id: "payment_type",
        header: "Оплата",
        enableSorting: false,
        cell: ({ row }) =>
            row.original.payment_type_label ||
            paymentTypeLabel(row.original.payment_type),
    },
    {
        id: "nm_count",
        header: "Товаров",
        enableSorting: false,
        cell: ({ row }) => nmCell(row.original),
    },
    {
        id: "actions",
        header: "Действия",
        enableSorting: false,
        cell: ({ row }) => actionsCell(row.original),
    },
    {
        accessorKey: "id",
        header: "ID кампании",
        enableSorting: false,
        cell: ({ row }) =>
            h(
                "span",
                { class: "tabular-nums text-muted-foreground" },
                row.original.id,
            ),
    },
]);

function getRowClass(item) {
    const selected = props.selectedId != null && Number(props.selectedId) === Number(item.id);
    const clickable = !!item.can_select;
    const base = clickable ? "cursor-pointer" : "cursor-default";

    if (selected) {
        return `${base} bg-primary/5`;
    }

    return base;
}

function onRowClick(item) {
    if (!item?.can_select) {
        return;
    }
    emit("select", item);
}
</script>

<template>
    <div
        class="ab-campaigns-table"
        @click="
            (event) => {
                if (event.target.closest('button')) return;
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
            max-height="calc(100dvh - 30rem)"
            empty-text="Нет подходящих кампаний"
        />
    </div>
</template>
