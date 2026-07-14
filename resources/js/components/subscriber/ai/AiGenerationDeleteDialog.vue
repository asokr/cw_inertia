<script setup>
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";

defineProps({
    open: { type: Boolean, default: false },
    mediaLabel: { type: String, required: true },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "confirm"]);

function close() {
    emit("update:open", false);
}
</script>

<template>
    <Dialog
        :open="open"
        title="Удалить сессию"
        :description="`Сессия и все ${mediaLabel} в ней будут удалены без возможности восстановления.`"
        @update:open="emit('update:open', $event)"
    >
        <template #footer>
            <Button variant="outline" :disabled="loading" @click="close">Отмена</Button>
            <Button variant="destructive" :disabled="loading" @click="emit('confirm')">
                {{ loading ? "Удаление…" : "Удалить" }}
            </Button>
        </template>
    </Dialog>
</template>