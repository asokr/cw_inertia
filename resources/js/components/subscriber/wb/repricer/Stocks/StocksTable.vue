<script setup>
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import { Pencil, RefreshCw, Trash2 } from "lucide-vue-next";
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
</script>

<template>
    <div class="overflow-x-auto rounded-md border">
        <table class="w-full text-sm">
            <thead class="border-b bg-muted/40">
                <tr>
                    <th class="px-3 py-2 text-left">nmID</th>
                    <th class="px-3 py-2 text-left">База</th>
                    <th class="px-3 py-2 text-left">Условия</th>
                    <th class="px-3 py-2 text-left">Активна</th>
                    <th class="px-3 py-2 text-left">Статус</th>
                    <th class="px-3 py-2 text-left">Действия</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="item in items" :key="item.id" class="border-b">
                    <td class="px-3 py-2">
                        <button
                            type="button"
                            class="text-primary underline hover:no-underline"
                            @click="emit('open-logs', item.nmID)"
                        >
                            {{ item.nmID }}
                        </button>
                    </td>
                    <td class="px-3 py-2">
                        {{ item.base_value != null ? `${item.base_value} ₽` : "После первого входа" }}
                    </td>
                    <td class="px-3 py-2">
                        <Button variant="outline" size="sm" @click="openTerms(item)">Открыть</Button>
                    </td>
                    <td class="px-3 py-2">{{ item.active ? "Да" : "Нет" }}</td>
                    <td class="px-3 py-2">{{ item.status ? "Вкл" : "Выкл" }}</td>
                    <td class="px-3 py-2">
                        <div class="flex gap-1">
                            <Button variant="outline" size="sm" @click="emit('edit', item)">
                                <Pencil class="h-3.5 w-3.5" />
                            </Button>
                            <Button
                                v-if="!item.status"
                                variant="outline"
                                size="sm"
                                title="Сброс"
                                @click="reset(item)"
                            >
                                <RefreshCw class="h-3.5 w-3.5" />
                            </Button>
                            <Button variant="outline" size="sm" @click="confirmDelete(item)">
                                <Trash2 class="h-3.5 w-3.5" />
                            </Button>
                        </div>
                    </td>
                </tr>
                <tr v-if="!items.length">
                    <td colspan="6" class="px-3 py-8 text-center text-muted-foreground">
                        Номенклатура не добавлена
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

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