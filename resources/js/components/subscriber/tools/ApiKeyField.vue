<script setup>
import { ref } from "vue";
import { Eye, EyeOff } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";

defineProps({
    modelValue: {
        type: String,
        default: "",
    },
    label: {
        type: String,
        default: "API-ключ",
    },
    placeholder: {
        type: String,
        default: "Введите API-ключ",
    },
    error: Boolean,
    disabled: Boolean,
});

defineEmits(["update:modelValue"]);

const visible = ref(false);
</script>

<template>
    <div class="space-y-2">
        <Label>{{ label }}</Label>
        <div class="relative">
            <Input
                :model-value="modelValue"
                :type="visible ? 'text' : 'password'"
                :placeholder="placeholder"
                :error="error"
                :disabled="disabled"
                class="pr-10"
                @update:model-value="$emit('update:modelValue', $event)"
            />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="absolute right-0 top-0 h-9 w-9"
                :disabled="disabled"
                @click="visible = !visible"
            >
                <EyeOff v-if="visible" class="h-4 w-4" />
                <Eye v-else class="h-4 w-4" />
            </Button>
        </div>
    </div>
</template>