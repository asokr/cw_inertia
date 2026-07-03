<script setup>
import { usePage } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import Alert from "@/components/ui/Alert.vue";

const page = usePage();
const visible = ref(false);
const message = ref("");
const variant = ref("default");

const flash = computed(() => page.props.flash ?? {});

watch(flash, (f) => {
    if (f.success) {
        message.value = f.success;
        variant.value = "success";
        visible.value = true;
    } else if (f.error) {
        message.value = f.error;
        variant.value = "destructive";
        visible.value = true;
    } else if (f.messages?.length) {
        message.value = f.messages[0];
        variant.value = "default";
        visible.value = true;
    } else {
        visible.value = false;
    }

    if (visible.value) {
        window.setTimeout(() => { visible.value = false; }, 4000);
    }
}, { deep: true, immediate: true });
</script>

<template>
    <Teleport to="body">
        <div v-if="visible" class="fixed bottom-4 right-4 z-[100] max-w-sm">
            <Alert :variant="variant">{{ message }}</Alert>
        </div>
    </Teleport>
</template>