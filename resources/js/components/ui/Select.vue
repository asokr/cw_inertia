<script setup>
import { cn } from "@/lib/utils";

defineProps({
    modelValue: [String, Number, null],
    disabled: Boolean,
    error: Boolean,
});
defineEmits(["update:modelValue"]);
</script>

<template>
    <select
        :value="modelValue ?? ''"
        :disabled="disabled"
        :class="cn(
            'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
            error && 'border-destructive focus-visible:ring-destructive'
        )"
        @change="$emit('update:modelValue', $event.target.value === '' ? null : $event.target.value)"
    >
        <slot />
    </select>
</template>