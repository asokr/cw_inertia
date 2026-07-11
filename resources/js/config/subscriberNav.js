import {
    Bot,
    CreditCard,
    Home,
    Image,
    Rocket,
    Sparkles,
    Type,
    Video,
    Warehouse,
} from "lucide-vue-next";

const toolCatalog = [
    {
        key: "wb_feedbacks",
        label: "Управление отзывами",
        href: "/panel/wb/feedbacks",
        permission: "subscriber wb feedbacks",
        group: "Wildberries",
        icon: Rocket,
        description: "Автоответы и ИИ для отзывов Wildberries",
        hasCabinets: true,
    },
    {
        key: "wb_profitability",
        label: "Рентабельность",
        href: "/panel/wb/profitability",
        permission: "subscriber wb profitability",
        group: "Wildberries",
        icon: Rocket,
        description: "Анализ прибыли и маржинальности по кабинету",
        hasCabinets: true,
    },
    {
        key: "wb_promocalculator",
        label: "Рентабельность акций",
        href: "/panel/wb/promocalculator",
        permission: "subscriber wb promo calculator",
        group: "Wildberries",
        icon: Rocket,
        description: "Расчёт выгоды участия в акциях WB",
        hasCabinets: false,
    },
    {
        key: "wb_price_calc",
        label: "Ценообразование",
        href: "/panel/wb/price-calc",
        permission: "subscriber wb price calculator",
        group: "Wildberries",
        icon: Rocket,
        description: "Расчёт цен с учётом комиссий и логистики",
        hasCabinets: true,
    },
    {
        key: "wb_repricer",
        label: "Репрайсер",
        href: "/panel/wb/repricer",
        permission: "subscriber wb repricer",
        group: "Wildberries",
        icon: Rocket,
        description: "Автоматическое управление ценами по стратегиям",
        hasCabinets: true,
    },
    {
        key: "wb_ai_cabinet_analyzer",
        label: "ИИ анализ кабинета",
        href: "/panel/wb/ai-cabinet-analyzer",
        permission: "subscriber wb ai cabinet analyzer",
        group: "Wildberries",
        icon: Rocket,
        description: "ИИ-отчёты по продажам, отзывам и рекламе",
        hasCabinets: true,
    },
    {
        key: "oz_feedbacks",
        label: "Управление отзывами",
        href: "/panel/oz/feedbacks",
        permission: "subscriber oz feedbacks",
        group: "Ozon",
        icon: Warehouse,
        description: "Автоответы и ИИ для отзывов Ozon",
        hasCabinets: true,
    },
    {
        key: "oz_price_calc",
        label: "Ценообразование",
        href: "/panel/oz/price-calc",
        permission: "subscriber oz price calc",
        group: "Ozon",
        icon: Warehouse,
        description: "Расчёт цен для FBO и FBS на Ozon",
        hasCabinets: true,
    },
    {
        key: "ai_text",
        label: "Текст",
        href: "/panel/ai/text",
        permission: "subscriber ai",
        group: "ИИ",
        icon: Type,
        description: "Описания, адаптации и rich-контент для карточек",
        hasCabinets: false,
    },
    {
        key: "ai_image",
        label: "Изображения",
        href: "/panel/ai/image",
        permission: "subscriber ai",
        group: "ИИ",
        icon: Image,
        description: "Генерация и редактирование визуалов для товаров",
        hasCabinets: false,
    },
    {
        key: "ai_video",
        label: "Видео",
        href: "/panel/ai/video",
        permission: "subscriber ai",
        group: "ИИ",
        icon: Video,
        description: "Генерация видеороликов и сцен для карточек",
        hasCabinets: false,
    },
];

export function getSubscriberNav({ can, hasRole, isAdmin = false }) {
    const isSuperAdmin = can("super admin") || hasRole("Супер-Админ") || hasRole("super-admin");

    if (!hasRole("Подписчик") && !isSuperAdmin && !isAdmin) {
        return { main: [], bottom: [] };
    }

    const main = [
        { label: "Главная", href: "/panel", icon: Home },
        {
            label: "Wildberries",
            icon: Rocket,
            children: toolCatalog
                .filter((tool) => tool.group === "Wildberries")
                .map(({ key, label, href, permission, description }) => ({
                    key,
                    label,
                    href,
                    permission,
                    description,
                })),
        },
        {
            label: "Ozon",
            icon: Warehouse,
            children: toolCatalog
                .filter((tool) => tool.group === "Ozon")
                .map(({ key, label, href, permission, description }) => ({
                    key,
                    label,
                    href,
                    permission,
                    description,
                })),
        },
        {
            label: "ИИ Инструменты",
            icon: Sparkles,
            children: toolCatalog
                .filter((tool) => tool.group === "ИИ")
                .map(({ key, label, href, permission, description }) => ({
                    key,
                    label,
                    href,
                    permission,
                    description,
                })),
        },
    ];

    const bottom = [
        {
            label: "Личный менеджер",
            href: "/panel/manager",
            icon: Bot,
            permission: "subscriber",
        },
        {
            label: "Тарифы",
            href: "/panel/plans",
            icon: CreditCard,
            permission: "subscriber",
            key: "plans",
            description: "Выбор и смена тарифа подписки",
        },
    ];

    return {
        main: main
            .map((item) => filterNavItem(item, can))
            .filter(Boolean),
        bottom: bottom
            .filter((item) => !item.permission || can(item.permission))
            .map((item) => ({ ...item, comingSoon: !isRouteAvailable(item.href) })),
    };
}

export function getSubscriberTools({ can }) {
    return toolCatalog
        .filter((tool) => !tool.permission || can(tool.permission))
        .filter((tool) => isRouteAvailable(tool.href))
        .map((tool) => ({ ...tool }));
}

function filterNavItem(item, can) {
    if (item.children) {
        const children = item.children
            .filter((child) => !child.permission || can(child.permission))
            .map((child) => ({
                ...child,
                comingSoon: !isRouteAvailable(child.href),
            }));

        if (children.length === 0) {
            return null;
        }

        return { ...item, children };
    }

    if (item.permission && !can(item.permission)) {
        return null;
    }

    return {
        ...item,
        comingSoon: item.href ? !isRouteAvailable(item.href) : false,
    };
}

const availableRoutes = new Set([
    "/panel",
    "/panel/plans",
    "/panel/user/profile",
    "/panel/user/history",
    "/panel/wb/feedbacks",
    "/panel/oz/feedbacks",
    "/panel/wb/price-calc",
    "/panel/oz/price-calc",
    "/panel/wb/repricer",
    "/panel/wb/profitability",
    "/panel/wb/ai-cabinet-analyzer",
    "/panel/wb/promocalculator",
    "/panel/ai",
    "/panel/ai/text",
    "/panel/ai/image",
    "/panel/ai/video",
    "/panel/manager",
]);

function isRouteAvailable(href) {
    return availableRoutes.has(href);
}