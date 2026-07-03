<script setup>
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import { Pencil, Trash2 } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import { formatTermValue, modifierLabel, normalizeTerms, priceTypeLabel } from "@/utils/repricerTerms";

const props = defineProps({
    items: { type: Array, default: () => [] },
    cabinetId: { type: [Number, String], required: true },
    logsUrl: { type: String, required: true },
});

const emit = defineEmits(["edit", "open-logs"]);

const deleteOpen = ref(false);
const selected = ref(null);
const periodsOpen = ref(false);
const periodsItem = ref(null);

function openPeriods(item) {
    periodsItem.value = item;
    periodsOpen.value = true;
}

function confirmDelete(item) {
    selected.value = item;
    deleteOpen.value = true;
}

function destroy() {
    router.delete(`/panel/wb/repricer/cabinets/${props.cabinetId}/time/${selected.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
        },
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
                    <th class="px-3 py-2 text-left">Скидка</th>
                    <th class="px-3 py-2 text-left">Тип</th>
                    <th class="px-3 py-2 text-left">Модификатор</th>
                    <th class="px-3 py-2 text-left">Периоды</th>
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
                    <td class="px-3 py-2">{{ item.base_discount != null ? `${item.base_discount}%` : "—" }}</td>
                    <td class="px-3 py-2">{{ priceTypeLabel(item.price_type) }}</td>
                    <td class="px-3 py-2">{{ modifierLabel(item) }}</td>
                    <td class="px-3 py-2">
                        <Button variant="outline" size="sm" @click="openPeriods(item)">
                            Периоды ({{ normalizeTerms(item.terms).length }})
                        </Button>
                    </td>
                    <td class="px-3 py-2">{{ item.active ? "Да" : "Нет" }}</td>
                    <td class="px-3 py-2">{{ item.status ? "Вкл" : "Выкл" }}</td>
                    <td class="px-3 py-2">
                        <div class="flex gap-1">
                            <Button variant="outline" size="sm" @click="emit('edit', item)">
                                <Pencil class="h-3.5 w-3.5" />
                            </Button>
                            <Button variant="outline" size="sm" @click="confirmDelete(item)">
                                <Trash2 class="h-3.5 w-3.5" />
                            </Button>
                        </div>
                    </td>
                </tr>
                <tr v-if="!items.length">
                    <td colspan="9" class="px-3 py-8 text-center text-muted-foreground">
                        Номенклатура не добавлена
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <Dialog :open="periodsOpen" title="Периоды стратегии" @update:open="periodsOpen = $event">
        <div v-if="periodsItem" class="space-y-2 text-sm">
            <div
                v-for="(period, index) in normalizeTerms(periodsItem.terms)"
                :key="index"
                class="grid grid-cols-4 gap-2 border-b py-2"
            >
                <span>{{ index + 1 }}</span>
                <span>{{ period.start }}</span>
                <span>{{ period.end }}</span>
                <span>{{ formatTermValue(periodsItem, period.value) }}</span>
            </div>
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