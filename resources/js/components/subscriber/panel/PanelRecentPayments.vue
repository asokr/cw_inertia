<script setup>
import { Link } from "@inertiajs/vue3";
import Badge from "@/components/ui/Badge.vue";
import Card from "@/components/ui/Card.vue";
import { formatPaymentStatusLabel, paymentStatusBadgeVariant } from "@/utils/paymentStatus";

defineProps({
    payments: { type: Array, default: () => [] },
});

function formatAmount(amount) {
    return new Intl.NumberFormat("ru-RU", {
        style: "currency",
        currency: "RUB",
        maximumFractionDigits: 0,
    }).format(Number(amount ?? 0));
}

function formatDate(value) {
    if (!value) return "—";
    return String(value);
}


</script>

<template>
    <Card class="subscriber-card--static border-border/70 bg-card/80 p-6 backdrop-blur dark:bg-card/95 dark:backdrop-blur-none">
        <div class="mb-4 flex items-center justify-between gap-3">
            <h2 class="text-base font-semibold tracking-tight">Последние платежи</h2>
            <Link href="/panel/user/history" class="text-sm text-primary hover:underline">
                Вся история
            </Link>
        </div>
        <div v-if="payments.length" class="divide-y divide-border/60">
            <div
                v-for="payment in payments"
                :key="payment.id"
                class="flex items-center justify-between gap-3 py-3 text-sm first:pt-0 last:pb-0"
            >
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ payment.description || "Платёж" }}</p>
                    <p class="text-xs text-muted-foreground">{{ formatDate(payment.created_at) }}</p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="font-medium tabular-nums">{{ formatAmount(payment.amount) }}</p>
                    <Badge v-if="payment.status" :variant="paymentStatusBadgeVariant(payment.status)" class="mt-1 text-[10px]">
                        {{ formatPaymentStatusLabel(payment.status) }}
                    </Badge>
                </div>
            </div>
        </div>
        <p v-else class="text-sm text-muted-foreground">Платежей пока нет.</p>
    </Card>
</template>