<script setup>
import { Head } from "@inertiajs/vue3";
import RepricerSubnav from "@/components/subscriber/wb/repricer/RepricerSubnav.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Card from "@/components/ui/Card.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";

const props = defineProps({
    cabinet: { type: Object, required: true },
    strategies: { type: Array, default: () => [] },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "Репрайсер", href: "/panel/wb/repricer" },
    { label: props.cabinet.name },
];
</script>

<template>
    <Head :title="`Репрайсер — ${cabinet.name}`" />

    <SubscriberLayout :title="cabinet.name" :breadcrumbs="breadcrumbs">
        <ToolPageHeader title="Выберите стратегию" :description="cabinet.name" />

        <RepricerSubnav :cabinet-id="cabinet.id" />

        <div class="mt-4 space-y-3">
            <Card
                v-for="strategy in strategies"
                :key="strategy.key"
                class="p-4 transition-colors hover:border-primary/40"
            >
                <a :href="strategy.href" class="block space-y-1">
                    <h3 class="font-medium text-primary">{{ strategy.title }}</h3>
                    <p class="text-sm text-muted-foreground">{{ strategy.description }}</p>
                </a>
            </Card>
        </div>
    </SubscriberLayout>
</template>