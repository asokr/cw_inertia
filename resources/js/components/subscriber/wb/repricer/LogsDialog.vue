<script setup>
import { ref, watch } from "vue";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    open: Boolean,
    logsUrl: { type: String, required: true },
    nmId: { type: [Number, String], default: null },
    strategy: { type: String, required: true },
});

const emit = defineEmits(["update:open"]);

const loading = ref(false);
const logs = ref([]);
const error = ref(null);
const { showError } = useFlashToast();

watch(
    () => props.open,
    async (isOpen) => {
        if (!isOpen || !props.nmId) return;

        loading.value = true;
        error.value = null;
        logs.value = [];

        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.content ?? "";
            const response = await fetch(props.logsUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": token,
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                body: JSON.stringify({
                    nmID: Number(props.nmId),
                    strategy: props.strategy,
                }),
            });

            const payload = await response.json();

            if (payload?.success) {
                logs.value = payload.data ?? [];
            } else {
                const message = Array.isArray(payload?.messages) ? payload.messages.join(" ") : "Не удалось загрузить логи";
                error.value = message;
                showError(message);
            }
        } catch {
            error.value = "Не удалось загрузить логи";
            showError("Не удалось загрузить логи");
        } finally {
            loading.value = false;
        }
    },
);
</script>

<template>
    <Dialog
        :open="open"
        :title="`Логи — nmID ${nmId ?? ''}`"
        class="max-w-2xl"
        @update:open="emit('update:open', $event)"
    >
        <div v-if="loading" class="py-6 text-center text-sm text-muted-foreground">Загрузка…</div>
        <div v-else-if="error" class="py-4 text-sm text-muted-foreground">Не удалось загрузить логи</div>
        <div v-else-if="!logs.length" class="py-4 text-sm text-muted-foreground">Логов нет</div>
        <div v-else class="max-h-96 space-y-2 overflow-y-auto">
            <div
                v-for="(log, index) in logs"
                :key="index"
                class="rounded-md border p-3 text-sm"
            >
                <p>{{ log.message }}</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ log.type }} · {{ log.created_at }}
                </p>
            </div>
        </div>

        <template #footer>
            <Button variant="outline" @click="emit('update:open', false)">Закрыть</Button>
        </template>
    </Dialog>
</template>