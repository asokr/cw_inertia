<script setup>
import { computed, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { Maximize2 } from "lucide-vue-next";
import EditableDataTable from "@/components/subscriber/tools/EditableDataTable.vue";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Input from "@/components/ui/Input.vue";
import { buildWbPriceCalcV3Columns } from "@/config/wbPriceCalcV3Columns";

const props = defineProps({
    items: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({}) },
    cardsMeta: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    showUrl: { type: String, required: true },
    loading: { type: Boolean, default: false },
});

const fullscreen = ref(false);
const searchInput = ref(props.filters.search ?? "");
const perPageOptions = [50, 100, 250];

const columns = computed(() => buildWbPriceCalcV3Columns(props.settings));

const sorting = computed(() => {
    if (!props.filters.sort_key) return [];
    return [{ id: props.filters.sort_key, desc: props.filters.sort_dir === "desc" }];
});

const pinnedColumns = { left: ["nm_id"] };

let searchTimeout;
watch(searchInput, (value) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => reload({ search: value || "", page: 1 }), 500);
});

function reload(overrides = {}) {
    router.get(
        props.showUrl,
        {
            page: overrides.page ?? props.filters.page ?? 1,
            per_page: overrides.per_page ?? props.filters.per_page ?? 250,
            sort_key: overrides.sort_key ?? props.filters.sort_key ?? undefined,
            sort_dir: overrides.sort_dir ?? props.filters.sort_dir ?? "asc",
            search: overrides.search ?? props.filters.search ?? "",
        },
        {
            only: ["cards", "cardsMeta", "filters", "cardsError"],
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
                <h3 class="text-base font-semibold">Номенклатура</h3>
                <Input
                    v-model="searchInput"
                    class="max-w-md flex-1"
                    placeholder="Поиск по артикулу, баркоду, бренду, предмету"
                />
            </div>
            <div class="flex items-center gap-2">
                <span v-if="cardsMeta.total > 0" class="text-sm text-muted-foreground">
                    Позиций: {{ cardsMeta.total }}
                </span>
                <Button variant="outline" size="sm" @click="fullscreen = true">
                    <Maximize2 class="mr-1 h-4 w-4" />
                    На весь экран
                </Button>
            </div>
        </div>

        <div v-if="loading" class="py-8 text-center text-sm text-muted-foreground">Загрузка…</div>
        <EditableDataTable
            v-else
            :columns="columns"
            :data="items"
            :pinned-columns="pinnedColumns"
            max-height="calc(100dvh - 14rem)"
            manual-sorting
            :sorting="sorting"
            empty-text="Номенклатура ещё не загружена. Нажмите «Обновить список товаров»."
            @sort-change="onSortChange"
        />

        <div v-if="cardsMeta.total > 0" class="flex flex-wrap items-center justify-between gap-3 text-sm">
            <div class="flex items-center gap-2">
                <span class="text-muted-foreground">На странице:</span>
                <select
                    class="rounded-md border bg-background px-2 py-1"
                    :value="cardsMeta.per_page"
                    @change="changePerPage(Number($event.target.value))"
                >
                    <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
                </select>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="cardsMeta.current_page <= 1"
                    @click="changePage(cardsMeta.current_page - 1)"
                >
                    Назад
                </Button>
                <span>{{ cardsMeta.current_page }} / {{ cardsMeta.last_page }}</span>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="cardsMeta.current_page >= cardsMeta.last_page"
                    @click="changePage(cardsMeta.current_page + 1)"
                >
                    Вперёд
                </Button>
            </div>
        </div>

        <Dialog :open="fullscreen" title="Номенклатура" class="max-w-[95vw]" @update:open="fullscreen = $event">
            <EditableDataTable
                :columns="columns"
                :data="items"
                :pinned-columns="pinnedColumns"
                max-height="calc(100dvh - 8rem)"
                manual-sorting
                :sorting="sorting"
                empty-text="Номенклатура ещё не загружена."
                @sort-change="onSortChange"
            />
        </Dialog>
    </div>
</template>