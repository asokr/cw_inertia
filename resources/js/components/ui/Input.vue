<script setup>
import { cn } from "@/lib/utils";

defineProps({
    modelValue: [String, Number],
    type: {
        type: String,
        default: "text",
    },
    placeholder: String,
    disabled: Boolean,
    error: Boolean,
});
defineEmits(["update:modelValue"]);
</script>

<template>
    <input
        :type="type"
        :value="modelValue"
        :placeholder="placeholder"
        :disabled="disabled"
        :class="cn(
            'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
            error && 'border-destructive focus-visible:ring-destructive'
        )"
        @input="$emit('update:modelValue', $event.target.value)"
    />
</template>