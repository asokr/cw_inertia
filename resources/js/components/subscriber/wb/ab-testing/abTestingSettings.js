export const SETTINGS_DEFAULTS = {
    impressions_per_photo: 100000,
    impressions_per_round: 10000,
    round_minutes: 60,
    cpm: 350,
};

export const SETTINGS_FIELDS = [
    {
        key: "impressions_per_photo",
        title: "Всего показов на одно фото",
        description: "Сколько показов набрать на каждом варианте фото. После достижения лимита на всех вариантах эксперимент завершится.",
        unit: "на фото",
        min: 1000,
        max: 50000000,
    },
    {
        key: "impressions_per_round",
        title: "Показов за круг",
        description: "Сколько показов набрать на одном варианте фото, прежде чем сменить главное изображение.",
        unit: "за круг",
        min: 100,
        max: 50000000,
    },
    {
        key: "round_minutes",
        title: "Длительность круга",
        description: "Минимальное время удержания варианта, даже если показы набрались раньше.",
        unit: "мин",
        min: 5,
        max: 1440,
    },
    {
        key: "cpm",
        title: "Ставка CPM",
        description: "Целевая ставка за 1000 показов в рекламной кампании эксперимента.",
        unit: "₽",
        min: 50,
        max: 50000,
    },
];

/**
 * @param {Record<string, number|string|null|undefined>|null|undefined} settings
 * @returns {{impressions_per_photo:number,impressions_per_round:number,round_minutes:number,cpm:number}}
 */
export function normalizeSettings(settings) {
    return {
        impressions_per_photo: toInt(settings?.impressions_per_photo, SETTINGS_DEFAULTS.impressions_per_photo),
        impressions_per_round: toInt(settings?.impressions_per_round, SETTINGS_DEFAULTS.impressions_per_round),
        round_minutes: toInt(settings?.round_minutes, SETTINGS_DEFAULTS.round_minutes),
        cpm: toInt(settings?.cpm, SETTINGS_DEFAULTS.cpm),
    };
}

/**
 * @param {Record<string, number>} settings
 * @returns {string}
 */
export function formatSettingsSummary(settings) {
    const s = normalizeSettings(settings);
    const fmt = (n) => new Intl.NumberFormat("ru-RU").format(n);

    return `${fmt(s.impressions_per_photo)} на фото • ${fmt(s.impressions_per_round)} за круг • ${fmt(s.round_minutes)} мин • CPM ${fmt(s.cpm)} ₽`;
}

/**
 * @param {Record<string, number>} settings
 * @returns {Record<string, string>}
 */
export function validateSettingsClient(settings) {
    const s = normalizeSettings(settings);
    const errors = {};

    if (s.impressions_per_photo < 1000 || s.impressions_per_photo > 50000000) {
        errors.impressions_per_photo = "Укажите от 1 000 до 50 000 000 показов на одно фото.";
    }
    if (s.impressions_per_round < 100) {
        errors.impressions_per_round = "Минимум 100 показов за круг.";
    } else if (s.impressions_per_round > s.impressions_per_photo) {
        errors.impressions_per_round = "Показов за круг не может быть больше, чем всего показов на одно фото.";
    }
    if (s.round_minutes < 5 || s.round_minutes > 1440) {
        errors.round_minutes = "Длительность круга: от 5 до 1440 минут.";
    }
    if (s.cpm < 50 || s.cpm > 50000) {
        errors.cpm = "CPM: от 50 до 50 000 ₽.";
    }

    return errors;
}

function toInt(value, fallback) {
    const n = Number(value);
    if (!Number.isFinite(n) || Number.isNaN(n)) {
        return fallback;
    }
    return Math.round(n);
}
