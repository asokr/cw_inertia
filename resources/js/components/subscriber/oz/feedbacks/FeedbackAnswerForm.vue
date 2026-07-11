<script setup>
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import Button from "@/components/ui/Button.vue";
import Textarea from "@/components/ui/Textarea.vue";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    feedback: { type: Object, required: true },
    signature: { type: String, default: "" },
    sendUrl: { type: String, required: true },
    generateUrl: { type: String, required: true },
});

const open = ref(false);
const text = ref("");
const sending = ref(false);
const generating = ref(false);
const sent = ref(false);
const { showError } = useFlashToast();

function toggle() {
    open.value = !open.value;
}

async function generate() {
    generating.value = true;
    try {
        const response = await fetch(props.generateUrl, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.content ?? "",
            },
            body: JSON.stringify({ feedback: props.feedback }),
            credentials: "same-origin",
        });
        const data = await response.json();
        if (data.success) {
            const generated = data.data ?? "";
            text.value = props.signature ? `${generated} ${props.signature}` : generated;
        } else {
            showError(data.messages?.[0] ?? "Не удалось сгенерировать ответ");
        }
    } catch {
        showError("Ошибка при обращении к ИИ");
    } finally {
        generating.value = false;
    }
}

function send() {
    if (!text.value.trim()) return;
    sending.value = true;
    router.post(
        props.sendUrl,
        { id: props.feedback.id, text: text.value },
        {
            preserveScroll: true,
            onFinish: () => {
                sending.value = false;
                sent.value = true;
            },
        },
    );
}
</script>

<template>
    <div>
        <button type="button" class="text-sm font-medium text-primary hover:underline" @click="toggle">
            {{ open ? "Закрыть" : "Ответить" }}
        </button>
        <div v-if="open" class="mt-3 space-y-3">
            <Textarea v-model="text" :rows="8" placeholder="Текст ответа на отзыв" />
            <div class="flex flex-wrap gap-2">
                <Button :disabled="sending || sent" @click="send">
                    {{ sent ? "Отправлено" : "Отправить ответ" }}
                </Button>
                <Button variant="outline" :disabled="generating || sent" @click="generate">
                    {{ generating ? "Генерация…" : "Сгенерировать ИИ" }}
                </Button>
            </div>
        </div>
    </div>
</template>