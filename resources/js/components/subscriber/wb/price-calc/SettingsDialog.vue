<script setup>
import { watch } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Label from "@/components/ui/Label.vue";
import Select from "@/components/ui/Select.vue";
import Switch from "@/components/ui/Switch.vue";

const props = defineProps({
    open: Boolean,
    settings: { type: Object, default: () => ({}) },
    saveUrl: { type: String, required: true },
});

const emit = defineEmits(["update:open"]);

const form = useForm({
    maintenance_type: "transfer",
    buyout_scope: "cabinet",
    hide_sizes: true,
    use_localization_index: false,
    use_storage: false,
    use_irp: true,
    commission_source: "fbs",
    acquiring_source: "manual",
});

watch(
    () => props.settings,
    (val) => {
        if (!val) return;
        form.maintenance_type = val.maintenance_type ?? "transfer";
        form.buyout_scope = val.buyout_scope ?? "cabinet";
        form.hide_sizes = val.hide_sizes !== undefined ? Boolean(val.hide_sizes) : true;
        form.use_localization_index = Boolean(val.use_localization_index);
        form.use_storage = Boolean(val.use_storage);
        form.use_irp = val.use_irp !== undefined ? Boolean(val.use_irp) : true;
        form.commission_source = val.commission_source ?? "fbs";
        form.acquiring_source = val.acquiring_source ?? "manual";
    },
    { immediate: true, deep: true },
);

function close() {
    emit("update:open", false);
}

function submit() {
    form.post(props.saveUrl, {
        preserveScroll: true,
        onSuccess: () => emit("update:open", false),
    });
}
</script>

<template>
    <Dialog :open="open" title="Настройки" @update:open="$emit('update:open', $event)">
        <div class="space-y-4 text-sm">
            <div class="space-y-2">
                <Label>% за ведение</Label>
                <div class="flex flex-wrap gap-2">
                    <Button
                        :variant="form.maintenance_type === 'transfer' ? 'default' : 'outline'"
                        size="sm"
                        @click="form.maintenance_type = 'transfer'"
                    >
                        От суммы к перечислению
                    </Button>
                    <Button
                        :variant="form.maintenance_type === 'sales' ? 'default' : 'outline'"
                        size="sm"
                        @click="form.maintenance_type = 'sales'"
                    >
                        От суммы продаж
                    </Button>
                </div>
            </div>

            <div class="space-y-2">
                <Label>Процент выкупа</Label>
                <div class="flex flex-wrap gap-2">
                    <Button
                        :variant="form.buyout_scope === 'cabinet' ? 'default' : 'outline'"
                        size="sm"
                        @click="form.buyout_scope = 'cabinet'"
                    >
                        Единый на кабинет
                    </Button>
                    <Button
                        :variant="form.buyout_scope === 'article' ? 'default' : 'outline'"
                        size="sm"
                        @click="form.buyout_scope = 'article'"
                    >
                        На каждый артикул
                    </Button>
                </div>
            </div>

            <label class="flex items-center justify-between gap-3">
                <span>Спрятать размеры</span>
                <Switch :model-value="form.hide_sizes" @update:model-value="form.hide_sizes = $event" />
            </label>
            <p v-if="!form.hide_sizes" class="text-xs text-muted-foreground">
                После сохранения нажмите «Обновить список товаров», чтобы загрузить все размеры.
            </p>

            <label class="flex items-center justify-between gap-3">
                <span>Учитывать индекс локализации</span>
                <Switch :model-value="form.use_localization_index" @update:model-value="form.use_localization_index = $event" />
            </label>
            <label class="flex items-center justify-between gap-3">
                <span>Учитывать хранение</span>
                <Switch :model-value="form.use_storage" @update:model-value="form.use_storage = $event" />
            </label>
            <label class="flex items-center justify-between gap-3">
                <span>Учитывать ИРП</span>
                <Switch :model-value="form.use_irp" @update:model-value="form.use_irp = $event" />
            </label>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label>Источник комиссии</Label>
                    <Select v-model="form.commission_source">
                        <option value="fbs">FBS</option>
                        <option value="fbo">FBO</option>
                        <option value="reports">Из фин. отчетов</option>
                        <option value="manual">Ручной ввод</option>
                    </Select>
                </div>
                <div class="space-y-2">
                    <Label>Эквайринг</Label>
                    <Select v-model="form.acquiring_source">
                        <option value="reports">Из фин. отчетов</option>
                        <option value="manual">Ручной ввод</option>
                    </Select>
                </div>
            </div>
        </div>

        <template #footer>
            <Button variant="outline" @click="close">Закрыть</Button>
            <Button :disabled="form.processing" @click="submit">Сохранить</Button>
        </template>
    </Dialog>
</template>