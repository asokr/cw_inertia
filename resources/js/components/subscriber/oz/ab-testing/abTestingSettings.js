export const SETTINGS_DEFAULTS = {
  impressions_per_photo: 100000,
  impressions_per_round: 10000,
  round_minutes: 30,
};

const BASE_SETTINGS_FIELDS = [
  {
    key: "impressions_per_photo",
    title: "Всего показов на одно фото",
    description:
      "Сколько показов из статистики рекламы набрать на каждом варианте. Когда лимит есть у всех фото, эксперимент завершится. Минимум 5 000.",
    unit: "на фото",
    min: 5000,
    max: 50000000,
  },
  {
    key: "impressions_per_round",
    title: "Показов за круг",
    description:
      "Сколько показов из статистики рекламы нужно текущему фото, чтобы поставить следующее. По времени фото не меняется. Минимум 1 000.",
    unit: "за круг",
    min: 1000,
    max: 50000000,
  },
];

export function settingsFields() {
  return [...BASE_SETTINGS_FIELDS];
}

/** @deprecated use settingsFields() — kept for callers that expect a static list */
export const SETTINGS_FIELDS = settingsFields();

/**
 * @param {Record<string, number|string|null|undefined>|null|undefined} settings
 * @returns {{impressions_per_photo:number,impressions_per_round:number,round_minutes:number}}
 */
export function normalizeSettings(settings) {
  return {
    impressions_per_photo: toInt(
      settings?.impressions_per_photo,
      SETTINGS_DEFAULTS.impressions_per_photo,
    ),
    impressions_per_round: toInt(
      settings?.impressions_per_round,
      SETTINGS_DEFAULTS.impressions_per_round,
    ),
    round_minutes: toInt(
      settings?.round_minutes,
      SETTINGS_DEFAULTS.round_minutes,
    ),
  };
}

/**
 * @param {Record<string, number>} settings
 * @returns {string}
 */
export function formatSettingsSummary(settings) {
  const s = normalizeSettings(settings);
  const fmt = (n) => new Intl.NumberFormat("ru-RU").format(n);

  return `${fmt(s.impressions_per_photo)} на фото • ${fmt(s.impressions_per_round)} за круг`;
}

/**
 * @param {Record<string, number>} settings
 * @returns {Record<string, string>}
 */
export function validateSettingsClient(settings) {
  const s = normalizeSettings(settings);
  const errors = {};

  if (s.impressions_per_photo < 5000 || s.impressions_per_photo > 50000000) {
    errors.impressions_per_photo =
      "Укажите от 5 000 до 50 000 000 показов на одно фото.";
  }
  if (s.impressions_per_round < 1000) {
    errors.impressions_per_round = "Минимум 1 000 показов за круг.";
  } else if (s.impressions_per_round > s.impressions_per_photo) {
    errors.impressions_per_round =
      "Показов за круг не может быть больше, чем всего показов на одно фото.";
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
