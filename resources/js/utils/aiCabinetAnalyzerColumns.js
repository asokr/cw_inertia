import { h } from "vue";
import {
    formatNmid,
    formatNumberSafe,
    formatPercentSafe,
    formatRatingAverageSafe,
    formatRatingDistributionSafe,
    formatReviewListSafe,
    formatTimeToReadySafe,
    getByPath,
} from "@/utils/aiCabinetAnalyzerFormatters";

export const columnGroups = [
    { id: "main", label: "Основные", keys: ["image", "nmid", "vendorCode", "advert_ids", "campaigns_count"] },
    { id: "ads", label: "Реклама", keys: ["clicks", "views", "spend", "orders", "ctr", "cpc", "cr"] },
    {
        id: "funnel",
        label: "Воронка продаж",
        keys: [
            "funnel.open_count", "funnel.cart_count", "funnel.order_count", "funnel.order_sum",
            "funnel.buyout_count", "funnel.buyout_sum", "funnel.cancel_count", "funnel.cancel_sum",
            "funnel.avg_price", "funnel.avg_orders_count_per_day", "funnel.share_order_percent",
            "funnel.add_to_wishlist", "funnel.localization_percent", "funnel.time_to_ready",
        ],
    },
    {
        id: "wbclub",
        label: "WB Club",
        keys: [
            "funnel.wb_club.order_count", "funnel.wb_club.order_sum", "funnel.wb_club.buyout_count",
            "funnel.wb_club.buyout_sum", "funnel.wb_club.cancel_count", "funnel.wb_club.cancel_sum",
            "funnel.wb_club.avg_price", "funnel.wb_club.buyout_percent", "funnel.wb_club.avg_order_count_per_day",
        ],
    },
    {
        id: "conversions",
        label: "Конверсии",
        keys: [
            "funnel.conversions.add_to_cart_percent",
            "funnel.conversions.cart_to_order_percent",
            "funnel.conversions.buyout_percent",
        ],
    },
    {
        id: "dynamics",
        label: "Динамика",
        keys: ["funnel.comparison.openCountDynamic", "funnel.comparison.orderCountDynamic"],
    },
    {
        id: "misc",
        label: "Прочее",
        keys: ["funnel.currency", "ads_vs_funnel.orders_gap", "ads_vs_funnel.orders_ratio_ads_to_funnel"],
    },
    {
        id: "reviews",
        label: "Отзывы",
        keys: [
            "reviews.pros", "reviews.cons", "reviews.bables", "reviews.rating_distribution",
            "reviews.average_rating", "reviews.photo_stats.with_photos", "reviews.photo_stats.without_photos",
        ],
    },
];

const columnDefs = [
    {
        key: "image",
        header: "Фото",
        isImage: true,
        renderCell: (row) => {
            if (row.image) {
                return h("img", {
                    src: row.image,
                    alt: `NMID ${row.nmid ?? ""}`,
                    class: "h-10 w-10 rounded object-cover",
                });
            }

            return h("div", {
                class: "flex h-10 w-10 items-center justify-center rounded bg-muted text-xs text-muted-foreground",
            }, "—");
        },
    },
    { key: "nmid", header: "NMID", format: (row) => formatNmid(row.nmid) },
    { key: "vendorCode", header: "Артикул поставщика", format: (row) => row.funnel?.raw_funnel_payload?.product?.vendorCode || row.vendorCode || "—" },
    { key: "advert_ids", header: "ID кампаний", format: (row) => Array.isArray(row.advert_ids) ? row.advert_ids.join(", ") : "—" },
    { key: "campaigns_count", header: "Количество кампаний", format: (row) => formatNumberSafe(row.campaigns_count) },
    { key: "clicks", header: "Клики", format: (row) => formatNumberSafe(row.clicks) },
    { key: "views", header: "Показы", format: (row) => formatNumberSafe(row.views) },
    { key: "spend", header: "Расход", format: (row) => formatNumberSafe(row.spend) },
    { key: "orders", header: "Заказы (реклама)", format: (row) => formatNumberSafe(row.orders) },
    { key: "ctr", header: "CTR, %", format: (row) => formatPercentSafe(row.ctr) },
    { key: "cpc", header: "CPC", format: (row) => formatNumberSafe(row.cpc) },
    { key: "cr", header: "CR, %", format: (row) => formatPercentSafe(row.cr) },
    { key: "funnel.open_count", header: "Воронка: открытия", format: (row) => formatNumberSafe(row.funnel?.open_count) },
    { key: "funnel.cart_count", header: "Воронка: корзины", format: (row) => formatNumberSafe(row.funnel?.cart_count) },
    { key: "funnel.order_count", header: "Воронка: заказы", format: (row) => formatNumberSafe(row.funnel?.order_count) },
    { key: "funnel.order_sum", header: "Воронка: сумма заказов", format: (row) => formatNumberSafe(row.funnel?.order_sum) },
    { key: "funnel.buyout_count", header: "Воронка: выкупы", format: (row) => formatNumberSafe(row.funnel?.buyout_count) },
    { key: "funnel.buyout_sum", header: "Воронка: сумма выкупов", format: (row) => formatNumberSafe(row.funnel?.buyout_sum) },
    { key: "funnel.cancel_count", header: "Воронка: отмены", format: (row) => formatNumberSafe(row.funnel?.cancel_count) },
    { key: "funnel.cancel_sum", header: "Воронка: сумма отмен", format: (row) => formatNumberSafe(row.funnel?.cancel_sum) },
    { key: "funnel.avg_price", header: "Воронка: средний чек", format: (row) => formatNumberSafe(row.funnel?.avg_price) },
    { key: "funnel.avg_orders_count_per_day", header: "Воронка: ср. заказов в день", format: (row) => formatNumberSafe(row.funnel?.avg_orders_count_per_day) },
    { key: "funnel.share_order_percent", header: "Воронка: доля заказов, %", format: (row) => formatPercentSafe(row.funnel?.share_order_percent) },
    { key: "funnel.add_to_wishlist", header: "Воронка: в избранное", format: (row) => formatNumberSafe(row.funnel?.add_to_wishlist) },
    { key: "funnel.localization_percent", header: "Воронка: локализация, %", format: (row) => formatPercentSafe(row.funnel?.localization_percent) },
    { key: "funnel.time_to_ready", header: "Ср. время дост.", format: (row) => formatTimeToReadySafe(row.funnel) },
    { key: "funnel.wb_club.order_count", header: "WB Club: заказы", format: (row) => formatNumberSafe(row.funnel?.wb_club?.order_count) },
    { key: "funnel.wb_club.order_sum", header: "WB Club: сумма заказов", format: (row) => formatNumberSafe(row.funnel?.wb_club?.order_sum) },
    { key: "funnel.wb_club.buyout_count", header: "WB Club: выкупы", format: (row) => formatNumberSafe(row.funnel?.wb_club?.buyout_count) },
    { key: "funnel.wb_club.buyout_sum", header: "WB Club: сумма выкупов", format: (row) => formatNumberSafe(row.funnel?.wb_club?.buyout_sum) },
    { key: "funnel.wb_club.cancel_count", header: "WB Club: отмены", format: (row) => formatNumberSafe(row.funnel?.wb_club?.cancel_count) },
    { key: "funnel.wb_club.cancel_sum", header: "WB Club: сумма отмен", format: (row) => formatNumberSafe(row.funnel?.wb_club?.cancel_sum) },
    { key: "funnel.wb_club.avg_price", header: "WB Club: средний чек", format: (row) => formatNumberSafe(row.funnel?.wb_club?.avg_price) },
    { key: "funnel.wb_club.buyout_percent", header: "WB Club: % выкупа", format: (row) => formatPercentSafe(row.funnel?.wb_club?.buyout_percent) },
    { key: "funnel.wb_club.avg_order_count_per_day", header: "WB Club: ср. заказов в день", format: (row) => formatNumberSafe(row.funnel?.wb_club?.avg_order_count_per_day) },
    { key: "funnel.conversions.add_to_cart_percent", header: "Конверсия: в корзину, %", format: (row) => formatPercentSafe(row.funnel?.conversions?.add_to_cart_percent) },
    { key: "funnel.conversions.cart_to_order_percent", header: "Конверсия: корзина → заказ, %", format: (row) => formatPercentSafe(row.funnel?.conversions?.cart_to_order_percent) },
    { key: "funnel.conversions.buyout_percent", header: "Конверсия: выкуп, %", format: (row) => formatPercentSafe(row.funnel?.conversions?.buyout_percent) },
    { key: "funnel.comparison.openCountDynamic", header: "Динамика открытий", format: (row) => formatNumberSafe(row.funnel?.comparison?.openCountDynamic) },
    { key: "funnel.comparison.orderCountDynamic", header: "Динамика заказов", format: (row) => formatNumberSafe(row.funnel?.comparison?.orderCountDynamic) },
    { key: "funnel.currency", header: "Валюта", format: (row) => row.funnel?.currency || "—" },
    { key: "ads_vs_funnel.orders_gap", header: "Разрыв заказов ads/funnel", format: (row) => formatNumberSafe(row.ads_vs_funnel?.orders_gap) },
    { key: "ads_vs_funnel.orders_ratio_ads_to_funnel", header: "Соотношение заказов ads/funnel", format: (row) => formatNumberSafe(row.ads_vs_funnel?.orders_ratio_ads_to_funnel) },
    { key: "reviews.pros", header: "Достоинства товара", format: (row) => formatReviewListSafe(row.reviews?.pros) },
    { key: "reviews.cons", header: "Недостатки товара", format: (row) => formatReviewListSafe(row.reviews?.cons) },
    { key: "reviews.bables", header: "Теги покупателя", format: (row) => formatReviewListSafe(row.reviews?.bables) },
    { key: "reviews.rating_distribution", header: "Распределение рейтинга", format: (row) => formatRatingDistributionSafe(row.reviews?.rating_distribution) },
    { key: "reviews.average_rating", header: "Средний рейтинг", format: (row) => formatRatingAverageSafe(row.reviews?.average_rating) },
    { key: "reviews.photo_stats.with_photos", header: "Отзывы с фото", format: (row) => formatNumberSafe(row.reviews?.photo_stats?.with_photos) },
    { key: "reviews.photo_stats.without_photos", header: "Отзывы без фото", format: (row) => formatNumberSafe(row.reviews?.photo_stats?.without_photos) },
];

export function buildAiCabinetAnalyzerColumns(visibleKeys = []) {
    const keys = new Set(visibleKeys);

    return columnDefs
        .filter((def) => keys.has(def.key))
        .map((def) => ({
            accessorKey: def.key,
            header: def.header,
            enableSorting: false,
            meta: { isImage: Boolean(def.isImage) },
            cell: ({ row }) => {
                if (typeof def.renderCell === "function") {
                    return def.renderCell(row.original);
                }

                return typeof def.format === "function"
                    ? def.format(row.original)
                    : getByPath(row.original, def.key) ?? "—";
            },
        }));
}

export const defaultVisibleGroupIds = ["main", "ads", "funnel"];