<script setup>
import axios from "axios";
import { reactive, ref } from "vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import Textarea from "@/components/ui/Textarea.vue";

const props = defineProps({
    fields: {
        type: Array,
        required: true,
    },
    subject: {
        type: String,
        default: "Новая заявка с сайта",
    },
});

const emit = defineEmits(["success"]);

function getCsrfToken() {
    const tokenMatch = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return tokenMatch ? decodeURIComponent(tokenMatch[1]) : "";
}

const form = reactive({});
const errors = reactive({});
const loading = ref(false);
const feedback = ref(null);

props.fields.forEach((field) => {
    form[field.name] = "";
    errors[field.name] = false;
});

function validate() {
    let hasError = false;

    props.fields.forEach((field) => {
        if (field.required && !String(form[field.name] ?? "").trim()) {
            errors[field.name] = true;
            hasError = true;
        } else {
            errors[field.name] = false;
        }
    });

    return hasError;
}

async function handleSubmit() {
    feedback.value = null;

    if (validate()) {
        feedback.value = {
            type: "error",
            message: "Пожалуйста, заполните все обязательные поля.",
        };
        return;
    }

    loading.value = true;

    try {
        const payload = { subject: props.subject };

        props.fields.forEach((field) => {
            payload[field.name] = form[field.name];
        });

        const { data } = await axios.post("/send-message", payload, {
            headers: {
                "X-XSRF-TOKEN": getCsrfToken(),
            },
        });

        if (data.success) {
            feedback.value = {
                type: "success",
                message: "Мы получили вашу заявку, менеджер свяжется с вами",
            };

            props.fields.forEach((field) => {
                form[field.name] = "";
            });

            emit("success");
            return;
        }

        feedback.value = {
            type: "error",
            message: data.messages?.[0] ?? "Ошибка при отправке сообщения",
        };
    } catch {
        feedback.value = {
            type: "error",
            message: "Ошибка при отправке. Попробуйте позже.",
        };
    } finally {
        loading.value = false;
    }
}

function onPhoneKeypress(event) {
    if (!/[0-9]/.test(event.key)) {
        event.preventDefault();
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="handleSubmit">
        <div v-for="field in fields" :key="field.name" class="space-y-2">
            <Label :for="field.name">{{ field.label }}</Label>

            <Textarea
                v-if="field.type === 'textarea'"
                :id="field.name"
                v-model="form[field.name]"
                :error="errors[field.name]"
                rows="4"
            />
            <Input
                v-else
                :id="field.name"
                v-model="form[field.name]"
                :type="field.type === 'tel' ? 'text' : field.type"
                :error="errors[field.name]"
                @keypress="field.type === 'tel' ? onPhoneKeypress($event) : undefined"
            />

            <p v-if="errors[field.name]" class="text-xs text-destructive">
                {{ field.label }} обязательно
            </p>
        </div>

        <p
            v-if="feedback"
            class="rounded-md px-3 py-2 text-sm"
            :class="feedback.type === 'success'
                ? 'bg-emerald-500/10 text-emerald-700'
                : 'bg-destructive/10 text-destructive'"
        >
            {{ feedback.message }}
        </p>

        <Button type="submit" class="w-full" size="lg" :disabled="loading">
            {{ loading ? "Отправка..." : "Отправить" }}
        </Button>
    </form>
</template>