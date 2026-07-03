<script setup>
import { Head, Link, router } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import SubscribersSubnav from "@/components/admin/SubscribersSubnav.vue";
import Button from "@/components/ui/Button.vue";
import Badge from "@/components/ui/Badge.vue";
import Card from "@/components/ui/Card.vue";

defineProps({
    plans: { type: Array, default: () => [] },
});

function toggleStatus(plan) {
    router.patch(`/cw-page/plans/${plan.id}/status`, { status: !plan.status }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Тарифные планы" />

    <AdminLayout title="Планы" :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Планы' }]">
        <PageHeader title="Тарифные планы" description="Управление подписочными тарифами">
            <template #actions>
                <Button as="a" href="/cw-page/plans/create">Добавить тариф</Button>
            </template>
        </PageHeader>

        <SubscribersSubnav />

        <div class="space-y-3">
            <Card
                v-for="plan in plans"
                :key="plan.id"
                :class="['p-4', !plan.status && 'opacity-60']"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold">{{ plan.name }}</h3>
                            <Badge :variant="plan.status ? 'success' : 'secondary'">{{ plan.status ? "Активен" : "Отключён" }}</Badge>
                            <Badge v-if="plan.hidden" variant="outline">Скрытый</Badge>
                        </div>
                        <p v-if="plan.description" class="text-sm text-muted-foreground" v-html="plan.description" />
                        <p class="text-sm"><span class="text-muted-foreground">Срок:</span> {{ plan.duration }} дн.</p>
                        <p class="text-sm"><span class="text-muted-foreground">Цена:</span> {{ plan.price }} ₽</p>
                        <p class="text-sm"><span class="text-muted-foreground">Разрешения:</span> {{ (plan.permissions ?? []).join(", ") }}</p>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <Button variant="outline" size="sm" @click="toggleStatus(plan)">
                            {{ plan.status ? "Отключить" : "Включить" }}
                        </Button>
                        <Button as="a" :href="`/cw-page/plans/${plan.id}/edit`" variant="outline" size="sm">Редактировать</Button>
                    </div>
                </div>
            </Card>
            <Card v-if="!plans.length" class="p-8 text-center text-sm text-muted-foreground">
                Тарифы не найдены
            </Card>
        </div>
    </AdminLayout>
</template>