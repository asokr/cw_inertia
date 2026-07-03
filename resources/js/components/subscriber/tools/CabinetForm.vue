<script setup>
import { computed } from "vue";
import ApiKeyField from "@/components/subscriber/tools/ApiKeyField.vue";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";

const props = defineProps({
    open: Boolean,
    title: {
        type: String,
        default: "Кабинет",
    },
    description: {
        type: String,
        default: "",
    },
    modelValue: {
        type: Object,
        default: () => ({
            name: "",
            apikey: "",
            client_id: "",
            brands: "",
        }),
    },
    marketplace: {
        type: String,
        default: "wb",
    },
    processing: Boolean,
    errors: {
        type: Object,
        default: () => ({}),
    },
});

const emit = defineEmits(["update:open", "update:modelValue", "submit"]);

const isOzon = computed(() => props.marketplace === "oz");

function updateField(field, value) {
    emit("update:modelValue", {
        ...props.modelValue,
        [field]: value,
    });
}

function close() {
    emit("update:open", false);
}

function submit() {
    emit("submit");
}
</script>

<template>
    <Dialog
        :open="open"
        :title="title"
        :description="description"
        @update:open="$emit('update:open', $event)"
    >
        <form class="space-y-4" @submit.prevent="submit">
            <div class="space-y-2">
                <Label>Название</Label>
                <Input
                    :model-value="modelValue.name"
                    placeholder="Мой кабинет"
                    :error="Boolean(errors.name)"
                    @update:model-value="updateField('name', $event)"
                />
                <p v-if="errors.name" class="text-xs text-destructive">{{ errors.name }}</p>
            </div>

            <div v-if="isOzon" class="space-y-2">
                <Label>Client ID</Label>
                <Input
                    :model-value="modelValue.client_id"
                    placeholder="Ozon Client ID"
                    :error="Boolean(errors.client_id)"
                    @update:model-value="updateField('client_id', $event)"
                />
                <p v-if="errors.client_id" class="text-xs text-destructive">{{ errors.client_id }}</p>
            </div>

            <ApiKeyField
                :model-value="modelValue.apikey"
                :label="isOzon ? 'API-ключ Ozon' : 'API-ключ Wildberries'"
                :error="Boolean(errors.apikey)"
                @update:model-value="updateField('apikey', $event)"
            />
            <p v-if="errors.apikey" class="text-xs text-destructive">{{ errors.apikey }}</p>

            <div v-if="!isOzon && modelValue.brands !== undefined" class="space-y-2">
                <Label>Бренды (через запятую)</Label>
                <Input
                    :model-value="modelValue.brands"
                    placeholder="Brand A, Brand B"
                    :error="Boolean(errors.brands)"
                    @update:model-value="updateField('brands', $event)"
                />
            </div>

            <slot />
        </form>

        <template #footer>
            <Button type="button" variant="outline" :disabled="processing" @click="close">
                Отмена
            </Button>
            <Button type="button" :disabled="processing" @click="submit">
                Сохранить
            </Button>
        </template>
    </Dialog>
</template>