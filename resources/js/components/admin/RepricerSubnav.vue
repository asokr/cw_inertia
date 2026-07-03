<script setup>
import { Link, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { cn } from "@/lib/utils";

const page = usePage();

const items = [
    { label: "Кабинеты", href: "/cw-page/services/repricer/cabinets" },
    { label: "Номенклатуры", href: "/cw-page/services/repricer/nmids" },
];

const currentPath = computed(() => page.url.split("?")[0]);
</script>

<template>
    <nav class="mb-4 flex flex-wrap gap-1 border-b pb-2">
        <Link
            v-for="item in items"
            :key="item.href"
            :href="item.href"
            :class="cn(
                'rounded-md px-3 py-1.5 text-sm transition-colors',
                currentPath === item.href || currentPath.startsWith(item.href + '/')
                    ? 'bg-muted font-medium text-foreground'
                    : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground'
            )"
        >
            {{ item.label }}
        </Link>
    </nav>
</template>