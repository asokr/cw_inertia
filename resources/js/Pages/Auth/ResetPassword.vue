<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import GuestLayout from "@/Layouts/GuestLayout.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: "" },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: "",
    password_confirmation: "",
});

function submit() {
    form.post("/reset-password");
}
</script>

<template>
    <Head title="Новый пароль" />

    <GuestLayout>
        <Card class="landing-page__fade landing-page__fade--2 w-full border-border/70 bg-card/90 p-6 shadow-xl shadow-primary/5 backdrop-blur sm:p-8">
            <div class="mb-6 space-y-1 text-center sm:text-left">
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Новый пароль</h1>
                <p class="text-sm text-muted-foreground sm:text-base">Придумайте новый пароль для вашего аккаунта</p>
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="email">Email</Label>
                    <Input id="email" v-model="form.email" type="email" :error="!!form.errors.email" />
                    <p v-if="form.errors.email" class="text-xs text-destructive">{{ form.errors.email }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="password">Пароль</Label>
                    <Input id="password" v-model="form.password" type="password" :error="!!form.errors.password" />
                    <p v-if="form.errors.password" class="text-xs text-destructive">{{ form.errors.password }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="password_confirmation">Подтверждение пароля</Label>
                    <Input id="password_confirmation" v-model="form.password_confirmation" type="password" />
                </div>

                <Button type="submit" class="w-full" size="lg" :disabled="form.processing">
                    Сохранить пароль
                </Button>
            </form>

            <p class="mt-6 text-center text-sm text-muted-foreground">
                <Link href="/login" class="font-medium text-primary hover:underline">Войти</Link>
            </p>
        </Card>
    </GuestLayout>
</template>