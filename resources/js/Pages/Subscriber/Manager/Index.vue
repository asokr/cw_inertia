<script setup>
import { Head } from "@inertiajs/vue3";
import { Headphones } from "lucide-vue-next";
import ContactForm from "@/components/landing/ContactForm.vue";
import Card from "@/components/ui/Card.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";

const fields = [
    {
        name: "phone",
        label: "Номер телефона",
        type: "tel",
        required: true,
    },
];
</script>

<template>
    <Head title="Личный менеджер" />

    <SubscriberLayout title="Личный менеджер">
        <div class="mx-auto max-w-lg">
            <Card class="p-6 sm:p-8">
                <div class="mb-6 flex items-start gap-4">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                        <Headphones class="h-5 w-5" />
                    </div>
                    <div class="space-y-1">
                        <h2 class="text-lg font-semibold">Связь с менеджером</h2>
                        <p class="text-sm text-muted-foreground">
                            Отправьте номер телефона — менеджер свяжется с вами и поможет с настройкой инструментов,
                            тарифом или любыми вопросами по платформе.
                        </p>
                    </div>
                </div>

                <ContactForm
                    :fields="fields"
                    subject="Заявка: личный менеджер (панель)"
                />
            </Card>
        </div>
    </SubscriberLayout>
</template>