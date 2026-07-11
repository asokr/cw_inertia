<script setup>
import { computed, ref, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import Select from "@/components/ui/Select.vue";
import Switch from "@/components/ui/Switch.vue";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    open: Boolean,
    cabinetId: { type: [Number, String], required: true },
    stock: { type: Object, default: null },
    storeUrl: { type: String, required: true },
    updateUrl: { type: String, default: "" },
    sizesUrl: { type: String, required: true },
});

const emit = defineEmits(["update:open"]);

const isEdit = computed(() => Boolean(props.stock?.id));
const sizesLoading = ref(false);
const { showError } = useFlashToast();

const form = useForm({
    name: "",
    nmID: "",
    strategy: 1,
    terms: { qty: null, data: [{ from: "", add_to_price: "", is_procent: false }] },
    status: false,
    base_value: null,
    base_discount: null,
});

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;



        if (props.stock) {
            form.name = props.stock.name ?? "";
            form.nmID = props.stock.nmID ?? "";
            form.strategy = Number(props.stock.strategy) || 1;
            form.terms = props.stock.terms ?? { qty: null, data: [] };
            form.status = Boolean(props.stock.status);
            form.base_value = props.stock.base_value ?? null;
            form.base_discount = props.stock.base_discount ?? null;

            if (form.strategy === 1 && !form.terms?.data?.length) {
                form.terms = { qty: form.terms?.qty ?? null, data: [{ from: "", add_to_price: "", is_procent: false }] };
            }
            if (form.strategy === 2 && !Array.isArray(form.terms)) {
                form.terms = [];
            }
        } else {
            form.reset();
            form.strategy = 1;
            form.terms = { qty: null, data: [{ from: "", add_to_price: "", is_procent: false }] };
            form.status = false;
        }
    },
);

watch(
    () => form.strategy,
    (strategy) => {
        if (strategy === 1 && !form.terms?.data) {
            form.terms = { qty: null, data: [{ from: "", add_to_price: "", is_procent: false }] };
        }
        if (strategy === 2 && !Array.isArray(form.terms)) {
            form.terms = [];
        }
    },
);

async function loadSizes(withSizes = true) {
    if (!form.nmID) return;

    sizesLoading.value = true;

    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? "";
        const response = await fetch(props.sizesUrl, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": token,
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
            body: JSON.stringify({
                nmID: Number(form.nmID),
                sizes: withSizes,
            }),
        });

        const payload = await response.json();

        if (!payload?.success) {
            showError(Array.isArray(payload?.messages) ? payload.messages.join(" ") : "Ошибка загрузки");
            return;
        }

        const data = payload.data ?? {};

        if (data.price != null) form.base_value = data.price;
        if (data.discount != null) form.base_discount = data.discount;

        if (form.strategy === 1) {
            form.terms = {
                qty: data.stocks ?? form.terms?.qty,
                data: form.terms?.data?.length
                    ? form.terms.data
                    : [{ from: "", add_to_price: "", is_procent: false }],
            };
        } else if (withSizes && data.sizes) {
            form.terms = Object.entries(data.sizes).map(([size, info]) => ({
                size,
                qty: info.qty ?? 0,
                values: [{ from: "", add_to_price: "", is_procent: false }],
                chrtId: info.chrtId,
            }));
        }
    } catch {
        showError("Не удалось загрузить данные из WB");
    } finally {
        sizesLoading.value = false;
    }
}

function addCondition() {
    if (!form.terms?.data) {
        form.terms = { qty: form.terms?.qty ?? null, data: [] };
    }
    form.terms.data.push({ from: "", add_to_price: "", is_procent: false });
}

function removeCondition(index) {
    form.terms.data.splice(index, 1);
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit("update:open", false),
    };

    form.transform((data) => ({
        ...data,
        nmID: Number(data.nmID),
        strategy: Number(data.strategy),
    }));

    if (isEdit.value) {
        form.put(props.updateUrl, options);
    } else {
        form.post(props.storeUrl, options);
    }
}
</script>

<template>
    <Dialog
        :open="open"
        :title="isEdit ? 'Редактирование стратегии' : 'Добавление номенклатуры'"
        class="max-w-3xl"
        @update:open="emit('update:open', $event)"
    >
        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label>Название</Label>
                    <Input v-model="form.name" />
                </div>
                <div class="space-y-2">
                    <Label>nmID</Label>
                    <Input v-model="form.nmID" type="number" :disabled="isEdit" />
                </div>
            </div>

            <div class="space-y-2">
                <Label>Стратегия</Label>
                <Select v-model="form.strategy">
                    <option :value="1">Номенклатура</option>
                    <option :value="2">На размер</option>
                </Select>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    :disabled="sizesLoading || !form.nmID"
                    @click="loadSizes(form.strategy === 2)"
                >
                    {{ form.strategy === 2 ? "Загрузить размеры" : "Посчитать остатки" }}
                </Button>
                <span v-if="form.terms?.qty != null" class="text-sm text-muted-foreground">
                    Остатки: {{ form.terms.qty }}
                </span>
                <span v-if="form.base_value != null" class="text-sm text-muted-foreground">
                    Цена: {{ form.base_value }} ₽
                </span>
            </div>


            <template v-if="form.strategy === 1">
                <div
                    v-for="(row, index) in form.terms?.data ?? []"
                    :key="index"
                    class="grid gap-3 rounded-md border p-3 sm:grid-cols-3"
                >
                    <Input v-model="row.from" placeholder="Остатков менее" />
                    <Input v-model="row.add_to_price" placeholder="Прибавляем" />
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="row.is_procent" type="checkbox" />
                        Процент
                    </label>
                </div>
                <Button type="button" variant="outline" size="sm" @click="addCondition">Добавить условие</Button>
            </template>

            <template v-else>
                <p v-if="!form.terms?.length" class="text-sm text-muted-foreground">
                    Загрузите размеры из WB
                </p>
                <div v-for="(sizeRow, index) in form.terms" :key="index" class="rounded-md border p-3">
                    <p class="mb-2 text-sm font-medium">Размер: {{ sizeRow.size }} ({{ sizeRow.qty }} ед.)</p>
                    <div
                        v-for="(value, vIndex) in sizeRow.values"
                        :key="vIndex"
                        class="mb-2 grid gap-2 sm:grid-cols-3"
                    >
                        <Input v-model="value.from" placeholder="Остатков менее" />
                        <Input v-model="value.add_to_price" placeholder="Прибавляем" />
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="value.is_procent" type="checkbox" />
                            Процент
                        </label>
                    </div>
                </div>
            </template>

            <div class="flex items-center gap-3">
                <Switch v-model="form.status" />
                <span class="text-sm">Применить стратегию: {{ form.status ? "Да" : "Нет" }}</span>
            </div>
        </form>

        <template #footer>
            <Button variant="outline" :disabled="form.processing" @click="emit('update:open', false)">Отмена</Button>
            <Button :disabled="form.processing" @click="submit">Сохранить</Button>
        </template>
    </Dialog>
</template>