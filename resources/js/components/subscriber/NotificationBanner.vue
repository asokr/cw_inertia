<script setup>
import { computed } from "vue";
import Alert from "@/components/ui/Alert.vue";
import { useSubscriberContext } from "@/composables/useSubscriberContext";

const { notify } = useSubscriberContext();

const variant = computed(() => {
    const type = notify.value?.type;
    if (type === "error") return "destructive";
    return "default";
});
</script>

<template>
    <Alert v-if="notify" :variant="variant" class="mb-6">
        <div class="space-y-1">
            <p v-if="notify.title" class="font-medium">{{ notify.title }}</p>
            <!-- eslint-disable-next-line vue/no-v-html -->
            <div class="text-sm text-muted-foreground [&_a]:font-medium [&_a]:text-primary [&_a]:underline" v-html="notify.text" />
        </div>
    </Alert>
</template>