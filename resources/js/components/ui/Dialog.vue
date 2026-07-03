<script setup>
import { onMounted, onUnmounted, watch } from "vue";
import { X } from "lucide-vue-next";
import { cn } from "@/lib/utils";
import Button from "./Button.vue";

const props = defineProps({
    open: Boolean,
    title: String,
    description: String,
    class: { type: String, default: "" },
});
const emit = defineEmits(["update:open"]);

function close() {
    emit("update:open", false);
}

function onKeydown(e) {
    if (e.key === "Escape" && props.open) close();
}

onMounted(() => document.addEventListener("keydown", onKeydown));
onUnmounted(() => document.removeEventListener("keydown", onKeydown));

watch(() => props.open, (v) => {
    document.body.style.overflow = v ? "hidden" : "";
});
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" @click="close" />
            <div
                :class="cn(
                    'relative z-10 w-full max-w-lg rounded-lg border bg-card p-6 shadow-lg',
                    $props.class
                )"
            >
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h2 v-if="title" class="text-lg font-semibold">{{ title }}</h2>
                        <p v-if="description" class="mt-1 text-sm text-muted-foreground">{{ description }}</p>
                    </div>
                    <Button variant="ghost" size="icon" @click="close">
                        <X class="h-4 w-4" />
                    </Button>
                </div>
                <slot />
                <div v-if="$slots.footer" class="mt-6 flex justify-end gap-2">
                    <slot name="footer" />
                </div>
            </div>
        </div>
    </Teleport>
</template>