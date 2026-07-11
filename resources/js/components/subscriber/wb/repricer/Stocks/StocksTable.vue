<script setup>
import { computed, h, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { Pencil, RefreshCw, Trash2 } from "lucide-vue-next";
import EditableDataTable from "@/components/subscriber/tools/EditableDataTable.vue";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";

const props = defineProps({
    items: { type: Array, default: () => [] },
    cabinetId: { type: [Number, String], required: true },
});

const emit = defineEmits(["edit", "open-logs"]);

const deleteOpen = ref(false);
const termsOpen = ref(false);
const selected = ref(null);
const termsItem = ref(null);

function openTerms(item) {
    termsItem.value = item;
    termsOpen.value = true;
}

function confirmDelete(item) {
    selected.value = item;
    deleteOpen.value = true;
}

function destroy() {
    router.delete(`/panel/wb/repricer/cabinets/${props.cabinetId}/stocks/${selected.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
        },
    });
}

function reset(item) {
    router.post(`/panel/wb/repricer/cabinets/${props.cabinetId}/stocks/${item.id}/reset`, {}, {
        preserveScroll: true,
    });
}

const columns = computed(() => [
    {
        accessorKey: "nmID",
        header: "nmID",
        enableSorting: true,
        cell: ({ row }) => h(
            "button",
            {
                type: "button",
                class: "text-primary underline hover:no-underline",
                onClick: () => emit("open-logs", row.original.nmID),
            },
            () => row.original.nmID,
        ),
    },
    {
        accessorKey: "base_value",
        header: "База",
        enableSorting: true,
        cell: ({ row }) => (
            row.original.base_value != null
                ? `${row.original.base_value} ₽`
                : "После первого входа"
        ),
    },
    {
        id: "terms",
        header: "Условия",
        enableSorting: false,
        cell: ({ row }) => h(
            Button,
            {
                variant: "outline",
                size: "sm",
                onClick: () => openTerms(row.original),
            },
            () => "Открыть",
        ),
    },
    {
        accessorKey: "active",
        header: "Активна",
        enableSorting: true,
        cell: ({ row }) => (row.original.active ? "Да" : "Нет"),
    },
    {
        accessorKey: "status",
        header: "Статус",
        enableSorting: true,
        cell: ({ row }) => (row.original.status ? "Вкл" : "Выкл"),
    },
    {
        id: "actions",
        header: "Действия",
        enableSorting: false,
        cell: ({ row }) => h("div", { class: "flex gap-1" }, [
            h(
                Button,
                {
                    variant: "outline",
                    size: "sm",
                    onClick: () => emit("edit", row.original),
                },
                () => h(Pencil, { class: "h-3.5 w-3.5" }),
            ),
            !row.original.status
                ? h(
                    Button,
                    {
                        variant: "outline",
                        size: "sm",
                        title: "Сброс",
                        onClick: () => reset(row.original),
                    },
                    () => h(RefreshCw, { class: "h-3.5 w-3.5" }),
                )
                : null,
            h(
                Button,
                {
                    variant: "outline",
                    size: "sm",
                    onClick: () => confirmDelete(row.original),
                },
                () => h(Trash2, { class: "h-3.5 w-3.5" }),
            ),
        ].filter(Boolean)),
    },
]);
</script>

<template>
    <EditableDataTable
        :columns="columns"
        :data="items"
        max-height="calc(100dvh - 14rem)"
        empty-text="Номенклатура не добавлена"
    />

    <Dialog :open="termsOpen" title="Условия стратегии" @update:open="termsOpen = $event">
        <div v-if="termsItem" class="space-y-3 text-sm">
            <template v-if="Number(termsItem.strategy) === 1">
                <p v-if="termsItem.terms?.qty">Текущие остатки: {{ termsItem.terms.qty }}</p>
                <div
                    v-for="(row, index) in termsItem.terms?.data ?? []"
                    :key="index"
                    class="flex gap-4 border-b py-2"
                >
                    <span>Менее {{ row.from }}</span>
                    <span>+{{ row.add_to_price }}{{ row.is_procent ? "%" : " ₽" }}</span>
                </div>
            </template>
            <template v-else>
                <div v-for="(size, index) in termsItem.terms ?? []" :key="index" class="border-b py-2">
                    <p class="font-medium">Размер {{ size.size }} ({{ size.qty }} ед.)</p>
                    <div v-for="(value, vIndex) in size.values ?? []" :key="vIndex" class="pl-3">
                        Менее {{ value.from }} → +{{ value.add_to_price }}{{ value.is_procent ? "%" : " ₽" }}
                    </div>
                </div>
            </template>
        </div>
    </Dialog>

    <Dialog :open="deleteOpen" title="Удаление" @update:open="deleteOpen = $event">
        <p class="text-sm">Удалить номенклатуру <strong>{{ selected?.nmID }}</strong>?</p>
        <template #footer>
            <Button variant="outline" @click="deleteOpen = false">Отмена</Button>
            <Button variant="destructive" @click="destroy">Удалить</Button>
        </template>
    </Dialog>
</template>