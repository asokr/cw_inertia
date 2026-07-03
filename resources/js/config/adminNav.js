import {
    Activity,
    BookOpen,
    CreditCard,
    KeyRound,
    LayoutDashboard,
    Mail,
    MessageSquare,
    Percent,
    Sparkles,
    Users,
    Warehouse,
} from "lucide-vue-next";

export function getAdminNav({ can, hasRole }) {
    const isSuperAdmin = can("super admin") || hasRole("Супер-Админ") || hasRole("super-admin");
    const groups = [
        {
            label: "Обзор",
            items: [
                { label: "Главная", href: "/cw-page", icon: LayoutDashboard },
            ],
        },
    ];

    if (can("blog.view")) {
        groups.push({
            label: "Блог",
            items: [
                { label: "Посты", href: "/cw-page/blog/posts", icon: BookOpen },
                { label: "Категории", href: "/cw-page/blog/categories", icon: BookOpen },
                { label: "Теги", href: "/cw-page/blog/tags", icon: BookOpen },
            ],
        });
    }

    if (isSuperAdmin) {
        groups.push({
            label: "Подписчики",
            items: [
                { label: "Список", href: "/cw-page/subscribers", icon: Users },
                { label: "Планы", href: "/cw-page/plans", icon: CreditCard },
                { label: "Экстра-лимиты", href: "/cw-page/extra-limits", icon: CreditCard },
                { label: "Оплаты", href: "/cw-page/payments", icon: CreditCard },
            ],
        });

        groups.push({
            label: "Управление",
            items: [
                { label: "Купоны", href: "/cw-page/coupons", icon: Percent },
                { label: "Письма", href: "/cw-page/sent-emails", icon: Mail },
                { label: "Роли", href: "/cw-page/roles", icon: KeyRound },
                { label: "Пользователи", href: "/cw-page/users", icon: Users },
            ],
        });

        groups.push({
            label: "Услуги",
            items: [
                {
                    label: "Отзывы",
                    children: [
                        { label: "Кабинеты", href: "/cw-page/services/feedbacks/cabinets" },
                        { label: "АвтоОтветы ИИ", href: "/cw-page/services/feedbacks/ai-answers" },
                    ],
                    icon: MessageSquare,
                },
                {
                    label: "Репрайсер",
                    children: [
                        { label: "Кабинеты", href: "/cw-page/services/repricer/cabinets" },
                        { label: "Номенклатуры", href: "/cw-page/services/repricer/nmids" },
                    ],
                    icon: Warehouse,
                },
                {
                    label: "ИИ-анализ",
                    children: [
                        { label: "Кабинеты", href: "/cw-page/services/ai-cabinet/cabinets" },
                        { label: "Промпты", href: "/cw-page/services/ai-cabinet/prompts" },
                    ],
                    icon: Sparkles,
                },
                { label: "ИИ запросы", href: "/cw-page/services/ai/marketplace-logs", icon: Sparkles },
                { label: "Архив расходов AI", href: "/cw-page/services/ai/costs-archive", icon: Sparkles },
                { label: "WB API Usage", href: "/cw-page/wb/api-usage", icon: Activity },
            ],
        });
    }

    return groups;
}

export function getDefaultAdminPath() {
    return "/cw-page";
}