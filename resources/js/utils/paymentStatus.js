const PAYMENT_STATUS_LABELS = {
    CREATE: "Создан",
    CREATED: "Создан",
    CONFIRMED: "Подтверждён",
    FAILED: "Неудачный",
    CANCELED: "Отменён",
    RETURNED: "Возврат",
};

const PAYMENT_STATUS_VARIANTS = {
    CONFIRMED: "success",
    CREATE: "secondary",
    CREATED: "secondary",
    FAILED: "destructive",
    CANCELED: "destructive",
    RETURNED: "warning",
};

export function formatPaymentStatusLabel(status) {
    if (!status) {
        return "—";
    }

    return PAYMENT_STATUS_LABELS[status] ?? status;
}

export function paymentStatusBadgeVariant(status) {
    return PAYMENT_STATUS_VARIANTS[status] ?? "outline";
}