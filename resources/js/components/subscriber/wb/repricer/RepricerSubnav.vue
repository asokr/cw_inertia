<script setup>
import { computed } from "vue";
import { Link, usePage } from "@inertiajs/vue3";

const props = defineProps({
    cabinetId: { type: [Number, String], required: true },
});

const page = usePage();
const base = `/panel/wb/repricer/cabinets/${props.cabinetId}`;

const items = computed(() => [
    { label: "Стратегии", href: base, match: (url) => url === base || url === `${base}/` },
    { label: "По времени", href: `${base}/time`, match: (url) => url.includes("/time") },
    { label: "От остатков", href: `${base}/stocks`, match: (url) => url.includes("/stocks") },
]);

const currentUrl = computed(() => page.url.split("?")[0]);
</script>

<template>
    <nav class="flex flex-wrap gap-2 border-b pb-3">
        <Link
            v-for="item in items"
            :key="item.href"
            :href="item.href"
            class="rounded-md px-3 py-1.5 text-sm transition-colors"
            :class="item.match(currentUrl)
                ? 'bg-primary text-primary-foreground'
                : 'bg-muted text-muted-foreground hover:text-foreground'"
        >
            {{ item.label }}
        </Link>
    </nav>
</template>