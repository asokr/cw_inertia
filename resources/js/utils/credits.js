export function creditsWord(count) {
    const abs = Math.abs(Number(count) || 0);
    const mod100 = abs % 100;
    const mod10 = abs % 10;

    if (mod100 >= 11 && mod100 <= 14) {
        return "кредитов";
    }

    if (mod10 === 1) {
        return "кредит";
    }

    if (mod10 >= 2 && mod10 <= 4) {
        return "кредита";
    }

    return "кредитов";
}

export function formatCredits(count) {
    const value = Number(count) || 0;

    return `${value.toLocaleString("ru-RU")} ${creditsWord(value)}`;
}

export function formatCreditsRemaining(count) {
    return `Осталось ${formatCredits(count)}`;
}
