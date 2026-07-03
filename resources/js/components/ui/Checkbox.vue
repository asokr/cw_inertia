<script setup>
import { cn } from "@/lib/utils";

defineProps({
    modelValue: Boolean,
    disabled: Boolean,
    id: String,
});
defineEmits(["update:modelValue"]);
</script>

<template>
    <input
        :id="id"
        type="checkbox"
        :checked="modelValue"
        :disabled="disabled"
        :class="cn(
            'h-4 w-4 rounded border border-input text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50'
        )"
        @change="$emit('update:modelValue', $event.target.checked)"
    />
</template>