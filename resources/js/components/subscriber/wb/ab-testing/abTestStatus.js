export const AB_TEST_STATUSES = {
    not_created: {
        label: "Не создан",
        variant: "secondary",
    },
    draft: {
        label: "Черновик",
        variant: "outline",
    },
    running: {
        label: "В процессе",
        variant: "default",
    },
    completed: {
        label: "Завершён",
        variant: "success",
    },
    error: {
        label: "Ошибка",
        variant: "destructive",
    },
};

export function resolveAbTestStatus(status) {
    const key = String(status || "not_created");

    return AB_TEST_STATUSES[key] ?? {
        label: key,
        variant: "secondary",
    };
}
