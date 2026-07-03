<script setup>
import { cn } from "@/lib/utils";

defineProps({
    modelValue: String,
    placeholder: String,
    disabled: Boolean,
    rows: { type: Number, default: 3 },
    error: Boolean,
});
defineEmits(["update:modelValue"]);
</script>

<template>
    <textarea
        :value="modelValue"
        :placeholder="placeholder"
        :disabled="disabled"
        :rows="rows"
        :class="cn(
            'flex min-h-[60px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
            error && 'border-destructive focus-visible:ring-destructive'
        )"
        @input="$emit('update:modelValue', $event.target.value)"
    />
</template>