import {
    Bot,
    Home,
    Rocket,
    Sparkles,
    Warehouse,
} from "lucide-vue-next";

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
            children: [
                {
                    label: "Управление отзывами",
                    href: "/panel/wb/feedbacks",
                    permission: "subscriber wb feedbacks",
                },
                {
                    label: "Рентабельность",
                    href: "/panel/wb/profitability",
                    permission: "subscriber wb profitability",
                },
                {
                    label: "Рентабельность акций",
                    href: "/panel/wb/promocalculator",
                    permission: "subscriber wb promo calculator",
                },
                {
                    label: "Ценообразование",
                    href: "/panel/wb/price-calc",
                    permission: "subscriber wb price calculator",
                },
                {
                    label: "Репрайсер",
                    href: "/panel/wb/repricer",
                    permission: "subscriber wb repricer",
                },
                {
                    label: "ИИ анализ кабинета",
                    href: "/panel/wb/ai-cabinet-analyzer",
                    permission: "subscriber wb ai cabinet analyzer",
                },
            ],
        },
        {
            label: "Ozon",
            icon: Warehouse,
            children: [
                {
                    label: "Управление отзывами",
                    href: "/panel/oz/feedbacks",
                    permission: "subscriber oz feedbacks",
                },
                {
                    label: "Ценообразование",
                    href: "/panel/oz/price-calc",
                    permission: "subscriber oz price calc",
                },
            ],
        },
        {
            label: "ИИ Инструменты",
            href: "/panel/ai",
            icon: Sparkles,
            permission: "subscriber ai",
        },
    ];

    const bottom = [
        {
            label: "Личный менеджер",
            href: "/panel/manager",
            icon: Bot,
            permission: "subscriber",
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
    "/panel/manager",
]);

function isRouteAvailable(href) {
    return availableRoutes.has(href);
}