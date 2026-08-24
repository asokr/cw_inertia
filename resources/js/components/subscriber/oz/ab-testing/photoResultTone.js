/**
 * Result delta (%) relative to the winner photo (max CTR) after experiment completes.
 * Winner is always 0%; others are ≤ 0 (lag behind winner).
 *
 * Color by absolute lag: green < 10, yellow 10–30, red > 30.
 *
 * @param {number|null|undefined} deltaPct
 * @returns {'muted'|'green'|'yellow'|'red'}
 */
export function resultDeltaTone(deltaPct) {
    if (deltaPct == null || Number.isNaN(Number(deltaPct))) {
        return "muted";
    }

    const value = Math.abs(Number(deltaPct));
    if (value < 10) {
        return "green";
    }
    if (value <= 30) {
        return "yellow";
    }
    return "red";
}

/**
 * @param {number|null|undefined} deltaPct
 * @returns {string}
 */
export function resultDeltaClass(deltaPct) {
    switch (resultDeltaTone(deltaPct)) {
        case "green":
            return "border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400";
        case "yellow":
            return "border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-400";
        case "red":
            return "border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-400";
        default:
            return "border-border/60 bg-muted/40 text-muted-foreground";
    }
}

/**
 * @param {number|null|undefined} deltaPct
 * @returns {string}
 */
export function formatResultDelta(deltaPct) {
    if (deltaPct == null || Number.isNaN(Number(deltaPct))) {
        return "—";
    }

    const value = Number(deltaPct);
    if (value === 0) {
        return "0%";
    }
    // Prefer proper minus sign for lag behind winner (values are typically ≤ 0).
    if (value < 0) {
        return `−${Math.abs(value).toFixed(0)}%`;
    }
    return `+${value.toFixed(0)}%`;
}
