<script setup>
import { useForm } from "@inertiajs/vue3";
import { watch } from "vue";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import Textarea from "@/components/ui/Textarea.vue";

const props = defineProps({
    open: { type: Boolean, default: false },
    defaultName: { type: String, default: "" },
    contextEmail: { type: String, default: "" },
    source: { type: String, default: "verify_email" },
});

const emit = defineEmits(["update:open"]);

const form = useForm({
    name: "",
    phone: "",
    message: "",
    source: props.source,
    context_email: "",
});

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            return;
        }

        form.clearErrors();
        form.name = props.defaultName;
        form.context_email = props.contextEmail;
        form.source = props.source;
    }
);

function close() {
    emit("update:open", false);
}

function onPhoneKeypress(event) {
    if (!/[0-9+]/.test(event.key)) {
        event.preventDefault();
    }
}

function submit() {
    form.post("/support-message", {
        preserveScroll: true,
        onSuccess: () => {
            form.reset("phone", "message");
            close();
        },
    });
}
</script>

<template>
    <Dialog :open="open" title="Написать в поддержку" description="Опишите проблему — мы обязательно поможем вам"
        @update:open="emit('update:open', $event)">
        <form class="space-y-4" @submit.prevent="submit">
            <div class="space-y-2">
                <Label for="support_name">Имя</Label>
                <Input id="support_name" v-model="form.name" autocomplete="name" :error="!!form.errors.name" />
                <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
            </div>

            <div class="space-y-2">
                <Label for="support_phone">Телефон</Label>
                <Input id="support_phone" v-model="form.phone" type="tel" placeholder="+79991234567" autocomplete="tel"
                    :error="!!form.errors.phone" @keypress="onPhoneKeypress" />
                <p v-if="form.errors.phone" class="text-xs text-destructive">{{ form.errors.phone }}</p>
            </div>

            <div class="space-y-2">
                <Label for="support_message">Сообщение</Label>
                <Textarea id="support_message" v-model="form.message" rows="4"
                    placeholder="Опишите проблему с подтверждением почты" :error="!!form.errors.message" />
                <p v-if="form.errors.message" class="text-xs text-destructive">{{ form.errors.message }}</p>
            </div>

            <p v-if="contextEmail" class="text-xs text-muted-foreground">
                Email аккаунта: <span class="font-medium text-foreground">{{ contextEmail }}</span>
            </p>

            <div class="flex justify-end gap-2 pt-2">
                <Button type="button" variant="ghost" @click="close">Отмена</Button>
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? "Отправка..." : "Отправить" }}
                </Button>
            </div>
        </form>
    </Dialog>
</template>