<script setup>
import { useForm, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);
const cooldown = ref(0);

const form = useForm({
    email: page.props.flash?.verification_email ?? "",
});

function submit() {
    if (cooldown.value > 0) return;

    form.post("/email/resend", {
        preserveScroll: true,
        onSuccess: () => {
            cooldown.value = 60;
            const interval = setInterval(() => {
                cooldown.value -= 1;
                if (cooldown.value <= 0) clearInterval(interval);
            }, 1000);
        },
    });
}
</script>

<template>
    <div class="rounded-md border bg-muted/40 p-4">
        <p class="mb-3 text-sm text-muted-foreground">
            Email не подтверждён. Проверьте почту или запросите письмо повторно.
        </p>

        <div v-if="!isAuthenticated" class="mb-3 space-y-2">
            <Label for="resend_email">Email</Label>
            <Input id="resend_email" v-model="form.email" type="email" />
            <p v-if="form.errors.email" class="text-xs text-destructive">{{ form.errors.email }}</p>
        </div>

        <Button variant="outline" size="sm" :disabled="form.processing || cooldown > 0" @click="submit">
            {{ cooldown > 0 ? `Повторить через ${cooldown}с` : "Отправить письмо повторно" }}
        </Button>
    </div>
</template>