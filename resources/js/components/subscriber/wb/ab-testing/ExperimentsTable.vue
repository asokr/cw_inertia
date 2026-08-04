<script setup>
import { computed, h } from "vue";
import EditableDataTable from "@/components/subscriber/tools/EditableDataTable.vue";
import Badge from "@/components/ui/Badge.vue";
import { resolveAbTestStatus } from "./abTestStatus";

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(["open"]);

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

function formatProgress(value) {
    const number = Number(value);
    if (Number.isNaN(number)) {
        return "0%";
    }

    return `${Math.max(0, Math.min(100, Math.round(number)))}%`;
}

function statusCell(row) {
    const status = resolveAbTestStatus(row.status);
    const title =
        row.status === "error" && row.error_message
            ? String(row.error_message)
            : status.label;

    return h(Badge, { variant: status.variant, title }, () => status.label);
}

function progressCell(row) {
    const mode = row.progress_mode || (row.status === "running" ? "views" : "setup");
    const percent = Math.max(0, Math.min(100, Math.round(Number(row.progress) || 0)));

    if (mode === "pending") {
        return h("div", { class: "flex min-w-[5.5rem] items-center gap-2", title: row.progress_label || "Ожидаем показы" }, [
            h("div", { class: "h-1.5 flex-1 overflow-hidden rounded-full bg-muted" }, [
                h("div", {
                    class: "h-full w-1/4 animate-pulse rounded-full bg-primary/40",
                }),
            ]),
            h("span", { class: "w-10 shrink-0 text-right text-xs text-muted-foreground" }, "…"),
        ]);
    }

    const label =
        mode === "setup"
            ? formatProgress(percent)
            : formatProgress(percent);

    return h(
        "div",
        {
            class: "flex min-w-[5.5rem] items-center gap-2",
            title: row.progress_label || (mode === "setup" ? "Готовность настройки" : "По показам"),
        },
        [
            h("div", { class: "h-1.5 flex-1 overflow-hidden rounded-full bg-muted" }, [
                h("div", {
                    class: "h-full rounded-full bg-primary transition-all",
                    style: { width: `${percent}%` },
                }),
            ]),
            h(
                "span",
                { class: "w-10 shrink-0 text-right text-xs tabular-nums text-muted-foreground" },
                label,
            ),
        ],
    );
}

const columns = computed(() => [
    {
        accessorKey: "name",
        header: "Название эксперимента",
        enableSorting: false,
        cell: ({ row }) =>
            h(
                "span",
                {
                    class: "font-medium text-foreground",
                    title: row.original.name,
                },
                row.original.name || "—",
            ),
    },
    {
        id: "status",
        header: "Статус",
        enableSorting: false,
        cell: ({ row }) => statusCell(row.original),
    },
    {
        id: "progress",
        header: "Прогресс",
        enableSorting: false,
        cell: ({ row }) => progressCell(row.original),
    },
    {
        id: "created_at",
        header: "Дата создания",
        enableSorting: false,
        cell: ({ row }) => formatDate(row.original.created_at),
    },
    {
        id: "finished_at",
        header: "Дата завершения",
        enableSorting: false,
        cell: ({ row }) => formatDate(row.original.finished_at),
    },
]);

function getRowClass() {
    return "cursor-pointer hover:bg-muted/40";
}

function onRowClick(item) {
    emit("open", item);
}
</script>

<template>
    <div
        class="ab-experiments-table"
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
            max-height="calc(100dvh - 28rem)"
            empty-text="Экспериментов пока нет"
        />
    </div>
</template>
