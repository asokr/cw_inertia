<script setup>
import { Head } from "@inertiajs/vue3";
import { Building2 } from "lucide-vue-next";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Card from "@/components/ui/Card.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";

const props = defineProps({
    toolName: { type: String, default: "Инструмент" },
    breadcrumbs: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="toolName" />

    <SubscriberLayout :title="toolName" :breadcrumbs="breadcrumbs">
        <ToolPageHeader :title="toolName" />

        <Card class="mx-auto max-w-xl p-8 text-center">
            <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-primary/10">
                <Building2 class="h-6 w-6 text-primary" />
            </div>
            <h2 class="text-lg font-semibold">Добавьте кабинет Ozon</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Для работы инструмента «{{ toolName }}» нужен хотя бы один кабинет Ozon
                с Client ID и API-ключом. Добавьте его через переключатель
                <span class="font-medium text-foreground">ОЗ</span> в шапке страницы.
            </p>
        </Card>
    </SubscriberLayout>
</template>
