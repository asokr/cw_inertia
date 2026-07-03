<script setup>
import { Head, router, useForm } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import ManagementSubnav from "@/components/admin/ManagementSubnav.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    user: { type: Object, required: true },
});

const form = useForm({
    name: props.user.name ?? "",
    surname: props.user.surname ?? "",
    email: props.user.email ?? "",
    password: "",
    password_confirmation: "",
});

function submit() {
    form.put(`/cw-page/users/${props.user.id}`);
}

function deleteUser() {
    if (!confirm("Удалить пользователя?")) return;
    router.delete(`/cw-page/users/${props.user.id}`);
}
</script>

<template>
    <Head :title="`Пользователь: ${user.name}`" />

    <AdminLayout
        title="Редактирование"
        :breadcrumbs="[
            { label: 'Админка', href: '/cw-page' },
            { label: 'Пользователи', href: '/cw-page/users' },
            { label: user.name },
        ]"
    >
        <PageHeader :title="user.name" :description="user.email" />

        <ManagementSubnav />

        <Card class="max-w-xl p-4">
            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm">Имя</label>
                    <Input v-model="form.name" required />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Фамилия</label>
                    <Input v-model="form.surname" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Email</label>
                    <Input v-model="form.email" type="email" required />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Новый пароль</label>
                    <Input v-model="form.password" type="password" autocomplete="new-password" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Подтверждение пароля</label>
                    <Input v-model="form.password_confirmation" type="password" autocomplete="new-password" />
                </div>
                <div class="flex gap-2">
                    <Button type="submit" :disabled="form.processing">Сохранить</Button>
                    <Button type="button" variant="destructive" @click="deleteUser">Удалить</Button>
                </div>
            </form>
        </Card>
    </AdminLayout>
</template>