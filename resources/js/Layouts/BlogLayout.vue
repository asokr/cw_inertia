<script setup>
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import LandingLayout from "@/Layouts/LandingLayout.vue";

const page = usePage();

const authenticated = computed(() => Boolean(page.props.auth?.user));
const homeUrl = computed(() => (page.props.auth?.user ? "/panel" : "/login"));
</script>

<template>
    <LandingLayout
        :authenticated="authenticated"
        :home-url="homeUrl"
        cabinet-label="В панель"
    >
        <div class="blog-page">
            <slot />
        </div>
    </LandingLayout>
</template>