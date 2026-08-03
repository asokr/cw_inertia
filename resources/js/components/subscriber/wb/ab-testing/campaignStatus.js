export const CAMPAIGN_STATUSES = {
    "-1": { label: "Удаляется", variant: "destructive" },
    4: { label: "Готова к запуску", variant: "default" },
    7: { label: "Завершена", variant: "secondary" },
    8: { label: "Отменена", variant: "secondary" },
    9: { label: "Активна", variant: "success" },
    11: { label: "Приостановлена", variant: "warning" },
};

export function resolveCampaignStatus(status) {
    const key = String(status ?? "");
    return (
        CAMPAIGN_STATUSES[key] ?? {
            label: key ? `Статус ${key}` : "—",
            variant: "outline",
        }
    );
}

export function bidTypeLabel(bidType) {
    switch (bidType) {
        case "manual":
            return "Ручная ставка";
        case "unified":
            return "Единая ставка";
        default:
            return bidType || "—";
    }
}

export function paymentTypeLabel(paymentType) {
    if (!paymentType) {
        return "—";
    }
    return String(paymentType).toUpperCase();
}
