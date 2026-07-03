<script setup>
import { Head } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import LastBlogPostsWidget from "@/components/admin/widgets/LastBlogPostsWidget.vue";
import LastPaymentsWidget from "@/components/admin/widgets/LastPaymentsWidget.vue";
import LastRegisteredWidget from "@/components/admin/widgets/LastRegisteredWidget.vue";
import LastSubscriptionsWidget from "@/components/admin/widgets/LastSubscriptionsWidget.vue";
import WbApiUsageStatsWidget from "@/components/admin/widgets/WbApiUsageStatsWidget.vue";

defineProps({
    isSuperAdmin: { type: Boolean, default: false },
    canViewBlog: { type: Boolean, default: false },
});
</script>

<template>
    <Head title="Главная" />

    <AdminLayout title="Главная" :breadcrumbs="[{ label: 'Админка' }]">
        <PageHeader title="Обзор" description="Сводка по подписчикам, оплатам и блогу" />

        <div class="space-y-6">
            <LastRegisteredWidget v-if="isSuperAdmin" :rows="5" />
            <LastSubscriptionsWidget v-if="isSuperAdmin" :rows="5" />
            <LastPaymentsWidget v-if="isSuperAdmin" :rows="5" />
            <LastBlogPostsWidget v-if="canViewBlog" :rows="5" />
            <WbApiUsageStatsWidget v-if="isSuperAdmin" />
        </div>
    </AdminLayout>
</template>