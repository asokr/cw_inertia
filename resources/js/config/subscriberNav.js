import {
    Bot,
    CreditCard,
    FlaskConical,
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
    },
    {
        key: "wb_profitability",
        label: "Рентабельность",
        href: "/panel/wb/profitability",
        permission: "subscriber wb profitability",
        group: "Wildberries",
        icon: Rocket,
        description: "Анализ прибыли и маржинальности по кабинету",
    },
    {
        key: "wb_promocalculator",
        label: "Рентабельность акций",
        href: "/panel/wb/promocalculator",
        permission: "subscriber wb promo calculator",
        group: "Wildberries",
        icon: Rocket,
        description: "Расчёт выгоды участия в акциях WB",
    },
    {
        key: "wb_price_calc",
        label: "Ценообразование",
        href: "/panel/wb/price-calc",
        permission: "subscriber wb price calculator",
        group: "Wildberries",
        icon: Rocket,
        description: "Расчёт цен с учётом комиссий и логистики",
    },
    {
        key: "wb_repricer",
        label: "Репрайсер",
        href: "/panel/wb/repricer",
        permission: "subscriber wb repricer",
        group: "Wildberries",
        icon: Rocket,
        description: "Автоматическое управление ценами по стратегиям",
    },
    {
        key: "wb_ai_cabinet_analyzer",
        label: "ИИ анализ кабинета",
        href: "/panel/wb/ai-cabinet-analyzer",
        permission: "subscriber wb ai cabinet analyzer",
        group: "Wildberries",
        icon: Rocket,
        description: "ИИ-отчёты по продажам, отзывам и рекламе",
    },
    {
        key: "wb_ab_testing",
        label: "A/B-тестирование",
        href: "/panel/wb/ab-testing",
        permission: "subscriber wb ab testing",
        group: "Wildberries",
        icon: FlaskConical,
        description: "Тест главной фотографии карточки товара",
    },
    {
        key: "oz_feedbacks",
        label: "Управление отзывами",
        href: "/panel/oz/feedbacks",
        permission: "subscriber oz feedbacks",
        group: "Ozon",
        icon: Warehouse,
        description: "Автоответы и ИИ для отзывов Ozon",
    },
    {
        key: "oz_price_calc",
        label: "Ценообразование",
        href: "/panel/oz/price-calc",
        permission: "subscriber oz price calc",
        group: "Ozon",
        icon: Warehouse,
        description: "Расчёт цен для FBO и FBS на Ozon",
    },
    {
        key: "ai_text",
        label: "Текст",
        href: "/panel/ai/text",
        permission: "subscriber ai",
        group: "ИИ",
        icon: Type,
        description: "Описания, адаптации и rich-контент для карточек",
    },
    {
        key: "ai_image",
        label: "Изображения",
        href: "/panel/ai/image",
        permission: "subscriber ai",
        group: "ИИ",
        icon: Image,
        description: "Генерация и редактирование визуалов для товаров",
    },
    {
        key: "ai_video",
        label: "Видео",
        href: "/panel/ai/video",
        permission: "subscriber ai",
        group: "ИИ",
        icon: Video,
        description: "Генерация видеороликов и сцен для карточек",
    },
];

function mapToolToNavChild(tool) {
    return {
        key: tool.key,
        label: tool.label,
        href: tool.href,
        permission: tool.permission,
        adminOnly: tool.adminOnly,
        description: tool.description,
    };
}

function buildGroupChildren(group) {
    return toolCatalog
        .filter((tool) => tool.group === group)
        .map(mapToolToNavChild);
}

export function getSubscriberNav({ can, hasRole, isAdmin = false }) {
    const isSuperAdmin = can("super admin") || hasRole("Супер-Админ") || hasRole("super-admin");
    const access = { can, isAdmin: isAdmin || isSuperAdmin };

    if (!hasRole("Подписчик") && !isSuperAdmin && !isAdmin) {
        return { main: [], bottom: [] };
    }

    const main = [
        { label: "Главная", href: "/panel", icon: Home },
        {
            label: "Wildberries",
            icon: Rocket,
            children: buildGroupChildren("Wildberries"),
        },
        {
            label: "Ozon",
            icon: Warehouse,
            children: buildGroupChildren("Ozon"),
        },
        {
            label: "ИИ Инструменты",
            icon: Sparkles,
            children: buildGroupChildren("ИИ"),
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
            .map((item) => filterNavItem(item, access))
            .filter(Boolean),
        bottom: bottom
            .filter((item) => !item.permission || can(item.permission))
            .map((item) => ({ ...item, comingSoon: !isRouteAvailable(item.href) })),
    };
}

export function getSubscriberTools({ can, isAdmin = false }) {
    return toolCatalog
        .filter((tool) => canSeeTool(tool, { can, isAdmin }))
        .filter((tool) => isRouteAvailable(tool.href))
        .map((tool) => ({ ...tool }));
}

function canSeeTool(tool, { can, isAdmin = false }) {
    if (tool.adminOnly && !isAdmin) {
        return false;
    }

    return !tool.permission || can(tool.permission);
}

function filterNavItem(item, { can, isAdmin = false }) {
    if (item.children) {
        const children = item.children
            .filter((child) => canSeeTool(child, { can, isAdmin }))
            .map((child) => ({
                ...child,
                comingSoon: !isRouteAvailable(child.href),
            }));

        if (children.length === 0) {
            return null;
        }

        return { ...item, children };
    }

    if (!canSeeTool(item, { can, isAdmin })) {
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
    "/panel/wb/ab-testing",
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