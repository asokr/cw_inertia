export const CAMPAIGN_STATUSES = {
    CAMPAIGN_STATE_RUNNING: { label: "Активна", variant: "success" },
    CAMPAIGN_STATE_INACTIVE: { label: "Остановлена", variant: "warning" },
    CAMPAIGN_STATE_STOPPED: { label: "Нет бюджета", variant: "destructive" },
    CAMPAIGN_STATE_PLANNED: { label: "Запланирована", variant: "outline" },
    CAMPAIGN_STATE_ARCHIVED: { label: "В архиве", variant: "secondary" },
    CAMPAIGN_STATE_FINISHED: { label: "Завершена", variant: "secondary" },
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
