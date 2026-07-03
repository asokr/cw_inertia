<script setup>
import { Link } from "@inertiajs/vue3";
import { Wallet } from "lucide-vue-next";
import { computed } from "vue";
import { useSubscriberContext } from "@/composables/useSubscriberContext";

const { balance } = useSubscriberContext();

const formattedBalance = computed(() => {
    const value = Number(balance.value ?? 0);
    return new Intl.NumberFormat("ru-RU", {
        style: "currency",
        currency: "RUB",
        maximumFractionDigits: 0,
    }).format(value);
});
</script>

<template>
    <div class="flex items-center gap-2 rounded-md border bg-card px-3 py-1.5">
        <Wallet class="h-4 w-4 text-muted-foreground" />
        <span class="text-sm font-medium tabular-nums">{{ formattedBalance }}</span>
        <Link
            href="/panel/user/profile"
            class="inline-flex h-7 items-center rounded-md border border-input bg-background px-2 text-xs font-medium hover:bg-accent hover:text-accent-foreground"
        >
            Пополнить
        </Link>
    </div>
</template>