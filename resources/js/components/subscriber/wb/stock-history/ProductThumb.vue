<script setup>
import { ref, watch } from "vue";

const props = defineProps({
    src: { type: String, default: "" },
});

const failed = ref(false);

watch(
    () => props.src,
    () => {
        failed.value = false;
    },
);
</script>

<template>
    <div class="h-10 w-10 overflow-hidden rounded-md bg-muted">
        <img
            v-if="src && !failed"
            :src="src"
            alt=""
            class="h-full w-full object-cover"
            @error="failed = true"
        />
    </div>
</template>
