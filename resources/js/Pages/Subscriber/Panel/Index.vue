<script setup>
import { Head, Link } from "@inertiajs/vue3";
import { CreditCard, User } from "lucide-vue-next";
import NotificationBanner from "@/components/subscriber/NotificationBanner.vue";
import Card from "@/components/ui/Card.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useSubscriberContext } from "@/composables/useSubscriberContext";

const { subscription } = useSubscriberContext();
</script>

<template>
    <Head title="Панель" />

    <SubscriberLayout title="Главная">
        <NotificationBanner />

        <div class="grid gap-4 md:grid-cols-2">
            <Card class="p-6">
                <div class="flex items-start gap-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-md bg-primary/10 text-primary">
                        <User class="h-5 w-5" />
                    </div>
                    <div class="space-y-2">
                        <h2 class="text-lg font-semibold">Профиль и подписка</h2>
                        <p class="text-sm text-muted-foreground">
                            Управляйте тарифом, балансом, лимитами и историей платежей.
                        </p>
                        <Link
                            href="/panel/user/profile"
                            class="inline-flex text-sm font-medium text-primary hover:underline"
                        >
                            Перейти в профиль
                        </Link>
                    </div>
                </div>
            </Card>

            <Card class="p-6">
                <div class="flex items-start gap-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-md bg-primary/10 text-primary">
                        <CreditCard class="h-5 w-5" />
                    </div>
                    <div class="space-y-2">
                        <h2 class="text-lg font-semibold">Текущий тариф</h2>
                        <p v-if="subscription?.status" class="text-sm text-muted-foreground">
                            Подписка активна. Инструменты будут доступны после завершения миграции модулей.
                        </p>
                        <p v-else class="text-sm text-muted-foreground">
                            Подписка неактивна. Пополните баланс и выберите тариф в профиле.
                        </p>
                    </div>
                </div>
            </Card>
        </div>

        <Card class="mt-6 p-6">
            <h2 class="mb-2 text-base font-semibold">Инструменты</h2>
            <p class="text-sm text-muted-foreground">
                Модули Wildberries, Ozon и ИИ переносятся в Фазе 3b. Пункты меню отмечены как «скоро» до готовности страниц.
            </p>
        </Card>
    </SubscriberLayout>
</template>