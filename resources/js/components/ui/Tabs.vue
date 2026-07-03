<script setup>
import { provide, ref, watch } from "vue";

const props = defineProps({
    modelValue: String,
    defaultValue: String,
});
const emit = defineEmits(["update:modelValue"]);

const active = ref(props.modelValue ?? props.defaultValue ?? "");

watch(() => props.modelValue, (v) => {
    if (v !== undefined) active.value = v;
});

function setActive(value) {
    active.value = value;
    emit("update:modelValue", value);
}

provide("tabs", { active, setActive });
</script>

<template>
    <div>
        <slot />
    </div>
</template>