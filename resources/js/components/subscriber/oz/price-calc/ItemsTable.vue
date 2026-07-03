<script setup>
import { computed, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { Maximize2 } from "lucide-vue-next";
import EditableDataTable from "@/components/subscriber/tools/EditableDataTable.vue";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Input from "@/components/ui/Input.vue";
import { buildTanStackColumns } from "@/utils/ozPriceCalcColumns";

const props = defineProps({
    mode: { type: String, required: true },
    items: { type: Array, default: () => [] },
    columns: { type: Array, default: () => [] },
    rowsMeta: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    showUrl: { type: String, required: true },
});

const fullscreen = ref(false);
const searchInput = ref(props.filters.search ?? "");
const perPageOptions = [10, 25, 50, 100];

const tableColumns = computed(() => buildTanStackColumns(props.columns));
const pinnedColumns = { left: ["ozon_article", "barcode"] };

const modeTitle = computed(() => (props.mode === "fbs" ? "Номенклатура FBS" : "Номенклатура FBO"));

const sorting = computed(() => {
    if (!props.filters.sort_key) return [];
    return [{ id: props.filters.sort_key, desc: props.filters.sort_dir === "desc" }];
});

let searchTimeout;
watch(searchInput, (value) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => reload({ search: value || "", page: 1 }), 500);
});

function reload(overrides = {}) {
    router.get(
        props.showUrl,
        {
            mode: props.mode,
            page: overrides.page ?? props.filters.page ?? 1,
            per_page: overrides.per_page ?? props.filters.per_page ?? 25,
            sort_key: overrides.sort_key ?? props.filters.sort_key ?? undefined,
            sort_dir: overrides.sort_dir ?? props.filters.sort_dir ?? "asc",
            search: overrides.search ?? props.filters.search ?? "",
        },
        {
            only: ["rows", "rowsMeta", "columns", "filters", "rowsError", "jobStatus"],
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function onSortChange(nextSorting) {
    const entry = nextSorting[0];
    if (!entry) {
        reload({ sort_key: undefined, sort_dir: "asc", page: 1 });
        return;
    }
    reload({ sort_key: entry.id, sort_dir: entry.desc ? "desc" : "asc", page: 1 });
}

function changePage(page) {
    reload({ page });
}

function changePerPage(perPage) {
    reload({ per_page: perPage, page: 1 });
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-3">
                <h3 class="text-base font-semibold">{{ modeTitle }}</h3>
                <Input
                    v-model="searchInput"
                    class="max-w-md flex-1"
                    placeholder="Поиск по артикулу или штрихкоду"
                />
            </div>
            <div class="flex items-center gap-2">
                <span v-if="rowsMeta.total > 0" class="text-sm text-muted-foreground">
                    Позиций: {{ rowsMeta.total }}
                </span>
                <Button variant="outline" size="sm" @click="fullscreen = true">
                    <Maximize2 class="mr-1 h-4 w-4" />
                    На весь экран
                </Button>
            </div>
        </div>

        <EditableDataTable
            :columns="tableColumns"
            :data="items"
            :pinned-columns="pinnedColumns"
            manual-sorting
            :sorting="sorting"
            empty-text="Номенклатура ещё не загружена. Нажмите «Обновить номенклатуру»."
            @sort-change="onSortChange"
        />

        <div v-if="rowsMeta.total > 0" class="flex flex-wrap items-center justify-between gap-3 text-sm">
            <div class="flex items-center gap-2">
                <span class="text-muted-foreground">На странице:</span>
                <select
                    class="rounded-md border bg-background px-2 py-1"
                    :value="rowsMeta.per_page"
                    @change="changePerPage(Number($event.target.value))"
                >
                    <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
                </select>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="rowsMeta.current_page <= 1"
                    @click="changePage(rowsMeta.current_page - 1)"
                >
                    Назад
                </Button>
                <span>{{ rowsMeta.current_page }} / {{ rowsMeta.last_page }}</span>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="rowsMeta.current_page >= rowsMeta.last_page"
                    @click="changePage(rowsMeta.current_page + 1)"
                >
                    Вперёд
                </Button>
            </div>
        </div>

        <Dialog :open="fullscreen" :title="modeTitle" class="max-w-[95vw]" @update:open="fullscreen = $event">
            <EditableDataTable
                :columns="tableColumns"
                :data="items"
                :pinned-columns="pinnedColumns"
                manual-sorting
                :sorting="sorting"
                empty-text="Номенклатура ещё не загружена."
                @sort-change="onSortChange"
            />
        </Dialog>
    </div>
</template>