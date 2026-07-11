<script setup>
import { computed, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import EditableDataTable from "@/components/subscriber/tools/EditableDataTable.vue";
import Button from "@/components/ui/Button.vue";
import Checkbox from "@/components/ui/Checkbox.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import {
    buildAiCabinetAnalyzerColumns,
    columnGroups,
    defaultVisibleGroupIds,
} from "@/utils/aiCabinetAnalyzerColumns";

const props = defineProps({
    showUrl: { type: String, required: true },
    reportId: { type: Number, default: null },
    items: { type: Array, default: () => [] },
    meta: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const visibleGroups = ref(new Set(defaultVisibleGroupIds));
const nmidInput = ref(props.filters.nmid ?? "");
const advertInput = ref(props.filters.advert_id ?? "");

const visibleKeys = computed(() => columnGroups
    .filter((group) => visibleGroups.value.has(group.id))
    .flatMap((group) => group.keys));

const columns = computed(() => buildAiCabinetAnalyzerColumns(visibleKeys.value));

let searchTimeout;
watch([nmidInput, advertInput], () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => reload({ page: 1 }), 500);
});

function reload(overrides = {}) {
    router.get(props.showUrl, {
        report_id: props.reportId,
        page: overrides.page ?? props.filters.page ?? 1,
        per_page: overrides.per_page ?? props.filters.per_page ?? 15,
        nmid: nmidInput.value,
        advert_id: advertInput.value,
    }, {
        only: ["nomenclatures", "nomenclaturesMeta", "nomenclatureFilters"],
        preserveState: true,
        preserveScroll: true,
    });
}

function toggleGroup(groupId) {
    if (visibleGroups.value.has(groupId)) {
        if (visibleGroups.value.size > 1) visibleGroups.value.delete(groupId);
    } else {
        visibleGroups.value.add(groupId);
    }
}

const hasFilters = computed(() => Boolean(String(nmidInput.value).trim() || String(advertInput.value).trim()));
</script>

<template>
    <div class="space-y-4 rounded-lg border p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="space-y-1">
                <Label for="nmid">Поиск по NMID</Label>
                <Input id="nmid" v-model="nmidInput" placeholder="NMID" class="w-48" />
            </div>
            <div class="space-y-1">
                <Label for="advert_id">ID рекламной кампании</Label>
                <Input id="advert_id" v-model="advertInput" placeholder="advert_id" class="w-48" />
            </div>
            <Button variant="outline" size="sm" :disabled="!hasFilters" @click="() => { nmidInput = ''; advertInput = ''; reload({ page: 1 }); }">
                Сбросить
            </Button>
            <Button variant="outline" size="sm" @click="reload()">Обновить</Button>
        </div>

        <div class="flex flex-wrap gap-3">
            <label v-for="group in columnGroups" :key="group.id" class="flex items-center gap-2 text-sm">
                <Checkbox :model-value="visibleGroups.has(group.id)" @update:model-value="toggleGroup(group.id)" />
                {{ group.label }}
            </label>
        </div>

        <EditableDataTable
            :columns="columns"
            :data="items"
            max-height="32rem"
            empty-text="В отчёте нет данных по номенклатурам."
        />

        <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
            <span>
                {{ meta.total ? `${((meta.current_page - 1) * meta.per_page) + 1}-${Math.min(meta.current_page * meta.per_page, meta.total)} из ${meta.total}` : "0 записей" }}
            </span>
            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" :disabled="meta.current_page <= 1" @click="reload({ page: meta.current_page - 1 })">
                    Назад
                </Button>
                <span>Стр. {{ meta.current_page }} / {{ meta.last_page || 1 }}</span>
                <Button variant="outline" size="sm" :disabled="meta.current_page >= (meta.last_page || 1)" @click="reload({ page: meta.current_page + 1 })">
                    Вперёд
                </Button>
            </div>
        </div>
    </div>
</template>