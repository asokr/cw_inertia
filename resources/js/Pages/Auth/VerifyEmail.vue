<script setup>
import { Head } from "@inertiajs/vue3";
import ResendVerification from "@/components/auth/ResendVerification.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";
import Card from "@/components/ui/Card.vue";
import Button from "@/components/ui/Button.vue";
</script>

<template>
    <Head title="Подтверждение email" />

    <GuestLayout>
        <Card class="landing-page__fade landing-page__fade--2 w-full space-y-4 border-border/70 bg-card/90 p-6 shadow-xl shadow-primary/5 backdrop-blur sm:p-8">
            <div class="space-y-1 text-center sm:text-left">
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Подтвердите email</h1>
                <p class="text-sm leading-relaxed text-muted-foreground sm:text-base">
                    Мы отправили письмо со ссылкой для подтверждения. После подтверждения вы сможете войти в аккаунт.
                </p>
            </div>

            <ResendVerification />

            <Button as="a" href="/login" variant="outline" size="lg" class="mt-4 w-full">
                Перейти ко входу
            </Button>
        </Card>
    </GuestLayout>
</template>