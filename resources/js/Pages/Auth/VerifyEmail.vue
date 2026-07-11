<script setup>
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import { CheckCircle2, Inbox, MailCheck } from "lucide-vue-next";
import { computed, ref } from "vue";
import ResendVerification from "@/components/auth/ResendVerification.vue";
import SupportContactDialog from "@/components/auth/SupportContactDialog.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";

const page = usePage();

const isAuthenticated = computed(() => !!page.props.auth?.user);

const supportDialogOpen = ref(false);

const email = computed(() => {
    return page.props.auth?.user?.email ?? page.props.flash?.verification_email ?? null;
});

const defaultName = computed(() => page.props.auth?.user?.name ?? "");

function logout() {
    router.post("/logout");
}

const steps = [
    { icon: Inbox, text: "Откройте письмо от CW Platform" },
    { icon: MailCheck, text: "Нажмите кнопку подтверждения в письме" },
    { icon: CheckCircle2, text: "Вас автоматически перенаправит в кабинет" },
];
</script>

<template>
    <Head title="Подтверждение email" />

    <GuestLayout>
        <Card class="landing-page__fade landing-page__fade--2 w-full border-border/70 bg-card/90 p-6 shadow-xl shadow-primary/5 backdrop-blur sm:p-8">
            <div class="flex flex-col items-center text-center sm:items-start sm:text-left">
                <div
                    class="mb-5 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-primary/20 to-primary/5 text-primary ring-1 ring-primary/20"
                >
                    <MailCheck class="h-7 w-7" />
                </div>

                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Проверьте почту</h1>

                <p v-if="email" class="mt-3 text-sm leading-relaxed text-muted-foreground sm:text-base">
                    Мы отправили ссылку для подтверждения на
                    <span class="mt-2 inline-block rounded-lg border border-border/70 bg-muted/50 px-3 py-1.5 font-mono text-sm font-medium text-foreground">
                        {{ email }}
                    </span>
                </p>
                <p v-else class="mt-3 text-sm leading-relaxed text-muted-foreground sm:text-base">
                    Мы отправили ссылку для подтверждения на указанный при регистрации адрес.
                </p>
            </div>

            <ol class="mt-6 space-y-3">
                <li
                    v-for="(step, index) in steps"
                    :key="step.text"
                    class="flex items-start gap-3 rounded-xl border border-border/50 bg-background/50 px-4 py-3 text-left"
                >
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                    >
                        {{ index + 1 }}
                    </span>
                    <div class="flex min-w-0 items-start gap-2 pt-0.5">
                        <component :is="step.icon" class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                        <span class="text-sm leading-snug text-foreground">{{ step.text }}</span>
                    </div>
                </li>
            </ol>

            <div class="mt-6">
                <ResendVerification />
            </div>

            <div class="mt-6 flex flex-col gap-2 sm:flex-row">
                <Button href="/" variant="outline" size="lg" class="w-full sm:flex-1">
                    На главную
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="lg"
                    class="w-full sm:flex-1"
                    @click="supportDialogOpen = true"
                >
                    Написать в поддержку
                </Button>
            </div>

            <SupportContactDialog
                v-model:open="supportDialogOpen"
                :default-name="defaultName"
                :context-email="email ?? ''"
                source="verify_email"
            />

            <p class="mt-4 text-center text-xs text-muted-foreground sm:text-left">
                <template v-if="isAuthenticated">
                    Ошиблись почтой?
                    <button type="button" class="font-medium text-primary hover:underline" @click="logout">
                        Выйти и зарегистрироваться снова
                    </button>
                </template>
                <template v-else>
                    Уже подтвердили email?
                    <Link href="/login" class="font-medium text-primary hover:underline">Войти в аккаунт</Link>
                </template>
            </p>
        </Card>
    </GuestLayout>
</template>