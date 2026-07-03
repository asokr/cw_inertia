<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import CouponField from "@/components/auth/CouponField.vue";
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
    name: "",
    email: "",
    phone: "",
    password: "",
    password_confirmation: "",
    coupon_code: "",
});

function submit() {
    form.post("/register");
}
</script>

<template>
    <Head title="Регистрация" />

    <GuestLayout>
        <Card class="landing-page__fade landing-page__fade--2 w-full border-border/70 bg-card/90 p-6 shadow-xl shadow-primary/5 backdrop-blur sm:p-8">
            <div class="mb-6 space-y-1 text-center sm:text-left">
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Регистрация</h1>
                <p class="text-sm text-muted-foreground sm:text-base">
                    5 дней тестового доступа ко всем возможностям платформы
                </p>
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="name">Имя</Label>
                    <Input id="name" v-model="form.name" :error="!!form.errors.name" />
                    <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="email">Email</Label>
                    <Input id="email" v-model="form.email" type="email" :error="!!form.errors.email" />
                    <p v-if="form.errors.email" class="text-xs text-destructive">{{ form.errors.email }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="phone">Телефон</Label>
                    <Input id="phone" v-model="form.phone" placeholder="+79991234567" :error="!!form.errors.phone" />
                    <p v-if="form.errors.phone" class="text-xs text-destructive">{{ form.errors.phone }}</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="password">Пароль</Label>
                        <Input id="password" v-model="form.password" type="password" :error="!!form.errors.password" />
                        <p v-if="form.errors.password" class="text-xs text-destructive">{{ form.errors.password }}</p>
                    </div>

                    <div class="space-y-2">
                        <Label for="password_confirmation">Подтверждение пароля</Label>
                        <Input id="password_confirmation" v-model="form.password_confirmation" type="password" />
                    </div>
                </div>

                <CouponField v-model="form.coupon_code" />

                <Button type="submit" class="w-full" size="lg" :disabled="form.processing">
                    Зарегистрироваться
                </Button>
            </form>

            <div v-if="vkEnabled || yandexEnabled" class="mt-6 space-y-3">
                <p class="text-center text-xs font-medium uppercase tracking-wide text-muted-foreground">или</p>
                <div class="grid gap-3">
                    <VkIdButton v-if="vkEnabled" :coupon-code="form.coupon_code || null" />
                    <YandexIdButton v-if="yandexEnabled" :coupon-code="form.coupon_code || null" />
                </div>
            </div>

            <p class="mt-6 text-center text-sm text-muted-foreground">
                Уже есть аккаунт?
                <Link href="/login" class="font-medium text-primary hover:underline">Войти</Link>
            </p>
        </Card>
    </GuestLayout>
</template>