const normalizeText = (value) => String(value || "").trim();

const buildCtaUrl = ({ ctaBaseUrl, campaignParams }) => {
    const baseUrl = normalizeText(ctaBaseUrl) || "/auth/register";
    const params = new URLSearchParams();

    Object.entries(campaignParams || {}).forEach(([key, value]) => {
        const normalized = normalizeText(value);
        if (normalized) {
            params.set(key, normalized);
        }
    });

    const query = params.toString();
    return query ? `${baseUrl}?${query}` : baseUrl;
};

export const conversionBlockTemplates = [
    {
        id: "ai-video-generator",
        title: "ИИ инструменты: генерация видео",
        description:
            "Промо-блок для привлечения в регистрацию на генерацию видео.",
        category: "AI",
        dedupeKey: "ИИ инструменты: генерация видео",
        htmlBuilder: (context) => {
            const ctaUrl = buildCtaUrl(context);

            return [
                '<section class="conversion-banner">',
                "  <h3>ИИ инструменты: генерация видео</h3>",
                "  <p><strong>5 дней бесплатно после регистрации</strong>: протестируйте генерацию видео без оплаты.</p>",
                "  <ul>",
                "    <li>Создавайте ролик по текстовому промпту для карточки товара.</li>",
                "    <li>Превращайте изображение товара в динамичное видео.</li>",
                "    <li>Делайте ролики длительностью до 15 секунд.</li>",
                "    <li>Выгружайте варианты в 420p и 720p.</li>",
                "    <li>Используйте форматы 9:16 и 16:9 под разные каналы.</li>",
                "  </ul>",
                "  <div>",
                "    <span>Хайлайт: ролик за минуты</span>",
                "    <span>Хайлайт: запуск без монтажа</span>",
                "  </div>",
                `  <a href="${ctaUrl}">Попробовать бесплатно и создать первое видео</a>`,
                "</section>",
            ].join("\n");
        },
    },
    {
        id: "ai-feedback-assistant",
        title: "ИИ для ответов на отзывы",
        description:
            "Конверсионный блок для акцента на автоматизации работы с отзывами.",
        category: "AI",
        dedupeKey: "ИИ для ответов на отзывы",
        htmlBuilder: (context) => {
            const ctaUrl = buildCtaUrl(context);

            return [
                '<section class="conversion-banner">',
                "  <h3>ИИ для ответов на отзывы</h3>",
                "  <p>Освободите время команды: шаблоны и AI-ответы для WB и Ozon в одном кабинете.</p>",
                "  <ul>",
                "    <li>Быстрое формирование ответа в нужном тоне.</li>",
                "    <li>Единый сценарий для разных маркетплейсов.</li>",
                "    <li>Меньше ручной рутины у менеджеров.</li>",
                "  </ul>",
                "  <p><strong>Хайлайт:</strong> выше скорость реакции и качество коммуникации.</p>",
                `  <a href="${ctaUrl}">Зарегистрироваться и включить AI-ответы</a>`,
                "</section>",
            ].join("\n");
        },
    },
    {
        id: "price-tools-stack",
        title: "Набор инструментов для маржинальности",
        description:
            "Блок для продвижения калькуляторов цен и контроля прибыли.",
        category: "Analytics",
        dedupeKey: "Набор инструментов для маржинальности",
        htmlBuilder: (context) => {
            const ctaUrl = buildCtaUrl(context);

            return [
                '<section class="conversion-banner">',
                "  <h3>Набор инструментов для маржинальности</h3>",
                "  <p>Сведите расчёты цены, комиссии и логистики в один рабочий контур.</p>",
                "  <ul>",
                "    <li>Расчёт минимальной цены с учётом издержек.</li>",
                "    <li>Импорт и экспорт таблиц для массовой работы.</li>",
                "    <li>Проверка экономики перед запуском акций.</li>",
                "  </ul>",
                "  <p><strong>Хайлайт:</strong> меньше ошибок перед публикацией цен.</p>",
                `  <a href="${ctaUrl}">Начать бесплатно и собрать свою модель цены</a>`,
                "</section>",
            ].join("\n");
        },
    },
];

export const getConversionTemplateById = (templateId) => {
    return (
        conversionBlockTemplates.find(
            (template) => template.id === templateId,
        ) || null
    );
};
