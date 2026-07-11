<script setup>
import { computed, h } from "vue";
import { Download, Eye, RefreshCw } from "lucide-vue-next";
import EditableDataTable from "@/components/subscriber/tools/EditableDataTable.vue";
import Alert from "@/components/ui/Alert.vue";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";
import {
    analysisStatusLabel,
    analysisStatusVariant,
    canRegenerateAnalysis,
    formatAnalysisDateTime,
} from "@/utils/aiCabinetAnalysisDisplay";

const props = defineProps({
    items: { type: Array, default: () => [] },
    polling: { type: Boolean, default: false },
    regeneratingId: { type: [Number, null], default: null },
});

const emit = defineEmits(["refresh", "open", "regenerate", "download"]);

const hasItems = computed(() => props.items.length > 0);
const showFirstRunHint = computed(() => !hasItems.value);
const showActions = computed(() => hasItems.value || props.polling);

function isRegenerating(analysisId) {
    return analysisId && Number(props.regeneratingId) === Number(analysisId);
}

const columns = computed(() => [
    {
        accessorKey: "template",
        header: "Шаблон",
        enableSorting: true,
        cell: ({ row }) => row.original.template?.name || "—",
    },
    {
        accessorKey: "status",
        header: "Статус",
        enableSorting: true,
        cell: ({ row }) => h(
            Badge,
            { variant: analysisStatusVariant(row.original.status) },
            () => analysisStatusLabel(row.original.status),
        ),
    },
    {
        accessorKey: "finished_at",
        header: "Завершён",
        enableSorting: true,
        cell: ({ row }) => h(
            "span",
            { class: "text-muted-foreground" },
            formatAnalysisDateTime(row.original.finished_at),
        ),
    },
    {
        id: "error",
        header: "Ошибка",
        enableSorting: false,
        cell: ({ row }) => {
            if (row.original.status === "failed") {
                return h(
                    "span",
                    { class: "text-xs text-destructive" },
                    row.original.error_message || "Ошибка выполнения",
                );
            }
            return h("span", { class: "text-muted-foreground" }, "—");
        },
    },
    {
        id: "actions",
        header: () => h("span", { class: "block text-center" }, "Действия"),
        enableSorting: false,
        cell: ({ row }) => {
            const item = row.original;
            const buttons = [
                h(
                    Button,
                    {
                        variant: "ghost",
                        size: "sm",
                        disabled: item.status === "processing",
                        onClick: () => emit("open", item),
                    },
                    () => h(Eye, { class: "h-4 w-4" }),
                ),
            ];

            if (item.status === "done") {
                buttons.push(
                    h(
                        Button,
                        {
                            variant: "ghost",
                            size: "sm",
                            onClick: () => emit("download", item),
                        },
                        () => h(Download, { class: "h-4 w-4" }),
                    ),
                );
            }

            if (canRegenerateAnalysis(item)) {
                buttons.push(
                    h(
                        Button,
                        {
                            variant: "ghost",
                            size: "sm",
                            disabled: isRegenerating(item.id),
                            onClick: () => emit("regenerate", item),
                        },
                        () => h(RefreshCw, {
                            class: ["h-4 w-4", { "animate-spin": isRegenerating(item.id) }],
                        }),
                    ),
                );
            }

            return h("div", { class: "flex items-center justify-center gap-1" }, buttons);
        },
    },
]);
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-lg font-semibold">ИИ анализы текущих данных</h3>
                <p class="text-sm text-muted-foreground">
                    Проводится ИИ анализ данных, собранных для кабинета за указанный период.
                    При обновлении данных кабинета ИИ-отчёты удаляются.
                </p>
            </div>
            <div v-if="showActions" class="flex items-center gap-2">
                <Badge v-if="polling" variant="default">Идёт обновление</Badge>
                <Button variant="outline" size="sm" @click="$emit('refresh')">Обновить</Button>
            </div>
        </div>

        <Alert v-if="showFirstRunHint">
            Проведите первый ИИ-анализ, чтобы увидеть историю запусков.
        </Alert>

        <EditableDataTable
            v-else
            :columns="columns"
            :data="items"
            max-height="calc(100dvh - 14rem)"
            empty-text="ИИ-анализы по этому отчёту пока не запускались"
        />
    </div>
</template>