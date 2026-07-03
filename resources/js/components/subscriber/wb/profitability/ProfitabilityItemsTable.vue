<script setup>
import { computed, ref } from "vue";
import EditableDataTable from "@/components/subscriber/tools/EditableDataTable.vue";
import Input from "@/components/ui/Input.vue";

const props = defineProps({
    title: { type: String, required: true },
    items: { type: Array, default: () => [] },
    columns: { type: Array, required: true },
    maxHeight: { type: String, default: "24rem" },
});

const search = ref("");

const filteredItems = computed(() => {
    const query = search.value.trim().toLowerCase();
    if (!query) return props.items;

    return props.items.filter((row) => Object.values(row).some((value) => String(value ?? "").toLowerCase().includes(query)));
});
</script>

<template>
    <div class="space-y-3 rounded-lg border p-3 md:p-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h3 class="font-semibold">{{ title }}</h3>
            <Input v-model="search" placeholder="Найти" class="max-w-xs" />
        </div>

        <div class="overflow-auto rounded-md border" :style="{ maxHeight }">
            <EditableDataTable
                :columns="columns"
                :data="filteredItems"
                empty-text="Нет данных"
            />
        </div>
    </div>
</template>