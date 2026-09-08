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
      "Сколько показов набрать на каждом варианте фото. После достижения лимита на всех вариантах эксперимент завершится.",
    unit: "на фото",
    min: 1000,
    max: 50000000,
  },
  {
    key: "impressions_per_round",
    title: "Показов за круг",
    description:
      "Лимит показов на текущем варианте за один круг. Как только наберётся — фото сменится. Если раньше истечёт «Длительность круга», смена произойдёт по времени, даже без этого лимита.",
    unit: "за круг",
    min: 100,
    max: 50000000,
  },
  {
    key: "round_minutes",
    title: "Длительность круга",
    description:
      "Максимальное время одного круга (мин). Минимум 30 минут — за меньшее время статистика показов ещё не успевает накопиться. По истечении фото сменится, даже если «Показов за круг» ещё не набрано. Если показы наберутся раньше — круг закончится по показам.",
    unit: "мин",
    min: 30,
    max: 1440,
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

  return `${fmt(s.impressions_per_photo)} на фото • ${fmt(s.impressions_per_round)} за круг • ${fmt(s.round_minutes)} мин`;
}

/**
 * @param {Record<string, number>} settings
 * @returns {Record<string, string>}
 */
export function validateSettingsClient(settings) {
  const s = normalizeSettings(settings);
  const errors = {};

  if (s.impressions_per_photo < 1000 || s.impressions_per_photo > 50000000) {
    errors.impressions_per_photo =
      "Укажите от 1 000 до 50 000 000 показов на одно фото.";
  }
  if (s.impressions_per_round < 100) {
    errors.impressions_per_round = "Минимум 100 показов за круг.";
  } else if (s.impressions_per_round > s.impressions_per_photo) {
    errors.impressions_per_round =
      "Показов за круг не может быть больше, чем всего показов на одно фото.";
  }
  if (s.round_minutes < 30 || s.round_minutes > 1440) {
    errors.round_minutes = "Длительность круга: от 30 до 1440 минут.";
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
