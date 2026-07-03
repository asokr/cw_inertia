<script setup>
import Alert from "@/components/ui/Alert.vue";
import Label from "@/components/ui/Label.vue";
import Select from "@/components/ui/Select.vue";

defineProps({
    cabinets: { type: Array, default: () => [] },
    modelValue: { type: [Number, String, null], default: null },
});

const emit = defineEmits(["update:modelValue"]);
</script>

<template>
    <div class="space-y-3">
        <h3 class="text-lg font-semibold">Шаг 1: Выберите кабинет Ценообразования</h3>

        <Alert v-if="!cabinets.length">
            Нет доступных кабинетов ценообразования. Добавьте кабинет в инструменте
            <a href="/panel/wb/price-calc" class="underline">Ценообразование</a>.
        </Alert>

        <div v-else class="max-w-md space-y-1">
            <Label for="price-calc-cabinet">Кабинет</Label>
            <Select
                id="price-calc-cabinet"
                :model-value="modelValue"
                @update:model-value="emit('update:modelValue', Number($event) || null)"
            >
                <option :value="null">Выберите</option>
                <option v-for="cabinet in cabinets" :key="cabinet.id" :value="cabinet.id">
                    {{ cabinet.name }}
                </option>
            </Select>
        </div>
    </div>
</template>