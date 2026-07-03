<script setup>
import { computed, inject, unref } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({
    value: { type: String, required: true },
    class: { type: String, default: "" },
});

const tabs = inject("tabs");
const isActive = computed(() => unref(tabs.active) === props.value);
</script>

<template>
    <button
        type="button"
        :class="cn(
            'inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all',
            isActive ? 'bg-background text-foreground shadow' : 'hover:text-foreground',
            $props.class
        )"
        @click="tabs.setActive(props.value)"
    >
        <slot />
    </button>
</template>