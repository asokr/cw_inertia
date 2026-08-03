<script setup>
import { computed } from "vue";
import Input from "@/components/ui/Input.vue";

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: "",
    },
    unit: {
        type: String,
        default: "",
    },
    modelValue: {
        type: [Number, String],
        default: "",
    },
    error: {
        type: String,
        default: "",
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["update:modelValue"]);

const displayValue = computed(() =>
    props.modelValue === null || props.modelValue === undefined ? "" : String(props.modelValue),
);

function onUpdate(value) {
    const raw = String(value ?? "").replace(/[^\d]/g, "");
    emit("update:modelValue", raw);
}
</script>

<template>
    <div
        class="rounded-lg border border-border/70 bg-muted/20 p-3.5 transition"
        :class="error ? 'border-destructive/50' : ''"
    >
        <div class="space-y-1">
            <p class="text-sm font-medium text-foreground">{{ title }}</p>
            <p v-if="description" class="text-xs leading-snug text-muted-foreground">
                {{ description }}
            </p>
        </div>

        <div class="mt-3 flex items-stretch gap-2">
            <Input
                type="text"
                inputmode="numeric"
                class="h-10 flex-1 font-medium tabular-nums"
                :model-value="displayValue"
                :disabled="disabled"
                :error="!!error"
                @update:model-value="onUpdate"
            />
            <span
                v-if="unit"
                class="inline-flex min-w-[3.25rem] shrink-0 items-center justify-center rounded-md border border-border/70 bg-muted/40 px-2 text-[11px] font-medium uppercase tracking-wide text-muted-foreground"
            >
                {{ unit }}
            </span>
        </div>

        <p v-if="error" class="mt-1.5 text-xs text-destructive">
            {{ error }}
        </p>
    </div>
</template>
