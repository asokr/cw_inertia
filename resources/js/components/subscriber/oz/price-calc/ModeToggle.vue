<script setup>
import { Package, Truck } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";

defineProps({
    modelValue: {
        type: String,
        default: "fbo",
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["update:modelValue"]);

const modes = [
    { key: "fbo", label: "FBO", icon: Package },
    { key: "fbs", label: "FBS", icon: Truck },
];
</script>

<template>
    <div class="inline-flex rounded-lg border bg-muted/30 p-1">
        <Button
            v-for="mode in modes"
            :key="mode.key"
            type="button"
            size="sm"
            :variant="modelValue === mode.key ? 'default' : 'ghost'"
            :disabled="disabled"
            class="gap-1.5"
            @click="emit('update:modelValue', mode.key)"
        >
            <component :is="mode.icon" class="h-4 w-4" />
            {{ mode.label }}
        </Button>
    </div>
</template>