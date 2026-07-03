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
    <div v-show="isActive" :class="cn('mt-4', $props.class)">
        <slot />
    </div>
</template>