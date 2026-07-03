<script setup>
import { Head, Link } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import ManagementSubnav from "@/components/admin/ManagementSubnav.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";

defineProps({
    email: { type: Object, required: true },
});
</script>

<template>
    <Head :title="email.subject" />

    <AdminLayout
        title="Письмо"
        :breadcrumbs="[
            { label: 'Админка', href: '/cw-page' },
            { label: 'Письма', href: '/cw-page/sent-emails' },
            { label: email.subject },
        ]"
    >
        <PageHeader :title="email.subject" description="Просмотр отправленного письма">
            <template #actions>
                <Button as="a" href="/cw-page/sent-emails" variant="outline">Назад к списку</Button>
            </template>
        </PageHeader>

        <ManagementSubnav />

        <Card class="p-4 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2 text-sm">
                <div>
                    <p class="text-muted-foreground">Кому</p>
                    <p class="font-medium">{{ email.to || "—" }}</p>
                </div>
                <div>
                    <p class="text-muted-foreground">Получатель</p>
                    <p class="font-medium">
                        <Link
                            v-if="email.subscriber_id"
                            :href="`/cw-page/subscribers/${email.subscriber_id}`"
                            class="text-primary hover:underline"
                        >
                            {{ email.recipient_name }}
                        </Link>
                        <span v-else>{{ email.recipient_name || "—" }}</span>
                    </p>
                </div>
                <div>
                    <p class="text-muted-foreground">Тип</p>
                    <p class="font-medium">{{ email.type || "—" }}</p>
                </div>
                <div>
                    <p class="text-muted-foreground">Статус</p>
                    <p class="font-medium">{{ email.status || "—" }}</p>
                </div>
                <div>
                    <p class="text-muted-foreground">Дата</p>
                    <p class="font-medium">{{ email.created_at }}</p>
                </div>
            </div>

            <div v-if="email.error_message" class="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
                {{ email.error_message }}
            </div>

            <div class="rounded-md border p-4">
                <div class="prose prose-sm max-w-none dark:prose-invert" v-html="email.body" />
            </div>
        </Card>
    </AdminLayout>
</template>