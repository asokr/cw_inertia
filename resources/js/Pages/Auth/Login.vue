<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import VkIdButton from "@/components/auth/VkIdButton.vue";
import YandexIdButton from "@/components/auth/YandexIdButton.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import Card from "@/components/ui/Card.vue";

defineProps({
    vkEnabled: Boolean,
    yandexEnabled: Boolean,
});

const form = useForm({
    email: "",
    password: "",
});

function submit() {
    form.post("/login");
}
</script>

<template>
    <Head title="Вход" />

    <GuestLayout>
        <Card class="landing-page__fade landing-page__fade--2 w-full border-border/70 bg-card/90 p-6 shadow-xl shadow-primary/5 backdrop-blur sm:p-8">
            <div class="mb-6 space-y-1 text-center sm:text-left">
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Вход</h1>
                <p class="text-sm text-muted-foreground sm:text-base">Рады видеть вас снова!</p>
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="email">Email</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        :error="!!form.errors.email"
                    />
                    <p v-if="form.errors.email" class="text-xs text-destructive">{{ form.errors.email }}</p>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Пароль</Label>
                        <Link href="/forgot-password" class="text-xs text-primary hover:underline">
                            Забыли пароль?
                        </Link>
                    </div>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        :error="!!form.errors.password"
                    />
                    <p v-if="form.errors.password" class="text-xs text-destructive">{{ form.errors.password }}</p>
                </div>

                <Button type="submit" class="w-full" size="lg" :disabled="form.processing">
                    Войти
                </Button>
            </form>

            <div v-if="vkEnabled || yandexEnabled" class="mt-6 space-y-3">
                <p class="text-center text-xs font-medium uppercase tracking-wide text-muted-foreground">или</p>
                <div class="grid gap-3">
                    <VkIdButton v-if="vkEnabled" />
                    <YandexIdButton v-if="yandexEnabled" />
                </div>
            </div>

            <p class="mt-6 text-center text-sm text-muted-foreground">
                Нет аккаунта?
                <Link href="/register" class="font-medium text-primary hover:underline">Регистрация</Link>
            </p>
        </Card>
    </GuestLayout>
</template>