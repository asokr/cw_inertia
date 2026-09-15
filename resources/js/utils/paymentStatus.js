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

function normalizeStatus(status) {
    return String(status ?? "").trim().toUpperCase();
}

export function formatPaymentStatusLabel(status) {
    if (!status) {
        return "—";
    }

    const key = normalizeStatus(status);

    return PAYMENT_STATUS_LABELS[key] ?? status;
}

export function paymentStatusBadgeVariant(status) {
    return PAYMENT_STATUS_VARIANTS[normalizeStatus(status)] ?? "outline";
}