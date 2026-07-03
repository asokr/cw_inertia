<script setup>
import Button from "@/components/ui/Button.vue";

defineProps({
    actions: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div class="flex items-center justify-end gap-1">
        <Button
            v-for="action in actions"
            :key="action.label"
            :variant="action.variant ?? 'outline'"
            size="sm"
            class="h-7 px-2"
            :as="action.href ? 'a' : 'button'"
            :href="action.href"
            :target="action.target"
            :rel="action.rel"
            :disabled="action.disabled"
            @click="action.onClick"
        >
            {{ action.label }}
        </Button>
    </div>
</template>