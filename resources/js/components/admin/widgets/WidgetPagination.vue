<script setup>
import { ChevronLeft, ChevronRight } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import Select from "@/components/ui/Select.vue";

defineProps({
    page: { type: Number, required: true },
    totalPages: { type: Number, required: true },
    perPage: { type: Number, required: true },
    perPageOptions: {
        type: Array,
        default: () => [5, 25, 50, 100],
    },
    loading: { type: Boolean, default: false },
    total: { type: Number, default: null },
});

const emit = defineEmits(["update:perPage", "prev", "next"]);

function onPerPageChange(value) {
    emit("update:perPage", Number(value));
}
</script>

<template>
    <div
        v-if="totalPages > 1 || total === null || total > perPage"
        class="mt-4 flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between"
    >
        <div class="text-sm text-muted-foreground">
            <span>
                Страница
                <span class="font-medium text-foreground">{{ page }}</span>
                из {{ totalPages }}
            </span>
            <span v-if="total !== null" class="ml-2 hidden sm:inline">· {{ total }} записей</span>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <label class="flex items-center gap-2 text-sm text-muted-foreground">
                <span class="whitespace-nowrap">На странице</span>
                <div class="w-[4.5rem]">
                    <Select
                        :model-value="perPage"
                        :disabled="loading"
                        @update:model-value="onPerPageChange"
                    >
                        <option v-for="option in perPageOptions" :key="option" :value="option">
                            {{ option }}
                        </option>
                    </Select>
                </div>
            </label>

            <div class="flex items-center gap-1">
                <Button
                    variant="outline"
                    size="sm"
                    class="h-8 gap-1 px-2.5"
                    :disabled="loading || page <= 1"
                    aria-label="Предыдущая страница"
                    @click="emit('prev')"
                >
                    <ChevronLeft class="h-4 w-4" />
                    <span class="hidden sm:inline">Назад</span>
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    class="h-8 gap-1 px-2.5"
                    :disabled="loading || page >= totalPages"
                    aria-label="Следующая страница"
                    @click="emit('next')"
                >
                    <span class="hidden sm:inline">Вперёд</span>
                    <ChevronRight class="h-4 w-4" />
                </Button>
            </div>
        </div>
    </div>
</template>