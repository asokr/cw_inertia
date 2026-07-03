<script setup>
import { computed, reactive, ref, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import Select from "@/components/ui/Select.vue";
import Switch from "@/components/ui/Switch.vue";

const props = defineProps({
    open: Boolean,
    cabinetId: { type: [Number, String], required: true },
    setting: { type: Object, default: null },
    storeUrl: { type: String, required: true },
    updateUrl: { type: String, default: "" },
});

const emit = defineEmits(["update:open"]);

const isEdit = computed(() => Boolean(props.setting?.id));

const form = useForm({
    name: "",
    nmID: "",
    price_type: "PRICE",
    strategy: "TIME",
    pricing_modifier_type: "FIXED",
    terms: [{ start: "", end: "", value: "" }],
    status: false,
});

const formError = ref("");

const termsText = computed(() => {
    if (form.pricing_modifier_type === "PROCENT") {
        return {
            label: form.price_type === "DISCOUNT" ? "Процент скидки" : "Процент цены",
            hint: "Значение в процентах",
        };
    }

    return {
        label: form.price_type === "DISCOUNT" ? "Скидка" : "Цена",
        hint: form.price_type === "DISCOUNT" ? "Значение скидки" : "Значение цены",
    };
});

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;

        formError.value = "";

        if (props.setting) {
            form.name = props.setting.name ?? "";
            form.nmID = props.setting.nmID ?? "";
            form.price_type = props.setting.price_type ?? "PRICE";
            form.strategy = props.setting.strategy ?? "TIME";
            form.pricing_modifier_type = props.setting.pricing_modifier_type ?? "FIXED";
            form.terms = (props.setting.terms?.length ? props.setting.terms : [{ start: "", end: "", value: "" }])
                .map((t) => ({ ...t }));
            form.status = Boolean(props.setting.status);
        } else {
            form.reset();
            form.price_type = "PRICE";
            form.strategy = "TIME";
            form.pricing_modifier_type = "FIXED";
            form.terms = [{ start: "", end: "", value: "" }];
            form.status = false;
        }
    },
);

function addPeriod() {
    form.terms.push({ start: "", end: "", value: "" });
}

function removePeriod(index) {
    if (form.terms.length <= 1) return;
    form.terms.splice(index, 1);
}

function submit() {
    formError.value = "";

    const options = {
        preserveScroll: true,
        onSuccess: () => emit("update:open", false),
        onError: () => {
            formError.value = "Проверьте заполнение полей";
        },
    };

    form.transform((data) => ({
        ...data,
        nmID: Number(data.nmID),
        terms: data.terms.map((t) => ({
            ...t,
            value: Number(t.value),
        })),
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
                    <Input v-model="form.name" placeholder="Метка" />
                </div>
                <div class="space-y-2">
                    <Label>Номенклатура (nmID)</Label>
                    <Input v-model="form.nmID" type="number" required :disabled="isEdit" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label>Менять</Label>
                    <Select v-model="form.price_type">
                        <option value="PRICE">Цену</option>
                        <option value="DISCOUNT">Скидку</option>
                    </Select>
                </div>
                <div class="space-y-2">
                    <Label>Способ изменения</Label>
                    <Select v-model="form.pricing_modifier_type">
                        <option value="FIXED">Фиксированное значение</option>
                        <option value="PROCENT">Процент</option>
                    </Select>
                </div>
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-medium">Периоды</h4>
                    <Button type="button" variant="outline" size="sm" @click="addPeriod">Добавить период</Button>
                </div>

                <div
                    v-for="(period, index) in form.terms"
                    :key="index"
                    class="grid gap-3 rounded-md border p-3 sm:grid-cols-4"
                >
                    <Input v-model="period.start" type="time" placeholder="Старт" />
                    <Input v-model="period.end" type="time" placeholder="Конец" />
                    <Input v-model="period.value" type="number" :placeholder="termsText.label" />
                    <Button
                        v-if="form.terms.length > 1"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="removePeriod(index)"
                    >
                        Удалить
                    </Button>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <Switch v-model="form.status" />
                <span class="text-sm">Применить стратегию: {{ form.status ? "Да" : "Нет" }}</span>
            </div>

            <p v-if="formError" class="text-sm text-destructive">{{ formError }}</p>
        </form>

        <template #footer>
            <Button variant="outline" :disabled="form.processing" @click="emit('update:open', false)">Отмена</Button>
            <Button :disabled="form.processing" @click="submit">Сохранить</Button>
        </template>
    </Dialog>
</template>