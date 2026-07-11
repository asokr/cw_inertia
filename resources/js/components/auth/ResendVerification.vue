<script setup>
import { useForm, usePage } from "@inertiajs/vue3";
import { Mail, RotateCcw } from "lucide-vue-next";
import { computed, ref } from "vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);
const cooldown = ref(0);
const justSent = ref(false);

const form = useForm({
    email: page.props.auth?.user?.email ?? page.props.flash?.verification_email ?? "",
});

const cooldownLabel = computed(() => {
    if (cooldown.value <= 0) {
        return null;
    }

    return `Повторная отправка через ${cooldown.value} сек.`;
});

function startCooldown() {
    cooldown.value = 60;
    justSent.value = true;

    const interval = setInterval(() => {
        cooldown.value -= 1;
        if (cooldown.value <= 0) {
            clearInterval(interval);
        }
    }, 1000);
}

function submit() {
    if (cooldown.value > 0 || form.processing) {
        return;
    }

    form.post("/email/resend", {
        preserveScroll: true,
        onSuccess: () => {
            startCooldown();
        },
    });
}
</script>

<template>
    <section class="verify-resend rounded-2xl border border-primary/25 bg-gradient-to-br from-primary/8 via-card to-card p-5 shadow-sm shadow-primary/10 sm:p-6">
        <div class="flex items-start gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/15 text-primary">
                <Mail class="h-5 w-5" />
            </div>
            <div class="min-w-0 flex-1">
                <h2 class="text-base font-semibold tracking-tight">Не пришло письмо?</h2>
                <p class="mt-1 text-sm leading-relaxed text-muted-foreground">
                    Отправим ссылку ещё раз. Если письма нет — загляните в папку «Спам».
                </p>
            </div>
        </div>

        <div v-if="!isAuthenticated" class="mt-4 space-y-2">
            <Label for="resend_email" class="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                Ваш email
            </Label>
            <Input
                id="resend_email"
                v-model="form.email"
                type="email"
                placeholder="name@example.com"
                class="bg-background/80"
            />
            <p v-if="form.errors.email" class="text-xs text-destructive">{{ form.errors.email }}</p>
        </div>

        <p v-if="justSent && cooldown > 0" class="mt-4 text-sm font-medium text-primary">
            Письмо отправлено — проверьте входящие.
        </p>

        <Button
            type="button"
            class="verify-resend__button mt-4 w-full gap-2"
            size="lg"
            :disabled="form.processing || cooldown > 0"
            @click="submit"
        >
            <RotateCcw class="h-4 w-4" :class="form.processing && 'animate-spin'" />
            <span>{{ cooldown > 0 ? cooldownLabel : "Отправить письмо повторно" }}</span>
        </Button>
    </section>
</template>

<style scoped>
.verify-resend__button:not(:disabled) {
    box-shadow: 0 8px 24px -8px hsl(var(--primary) / 0.55);
}
</style>