<script setup>
import { Link } from "@inertiajs/vue3";
import { computed, onMounted, reactive } from "vue";
import LimitList from "@/components/admin/widgets/LimitList.vue";
import OAuthProviderBadge from "@/components/admin/OAuthProviderBadge.vue";
import Card from "@/components/ui/Card.vue";
import WidgetPagination from "@/components/admin/widgets/WidgetPagination.vue";
import { useAdminWidgetApi } from "@/composables/useAdminWidgetApi";

const props = defineProps({
    rows: { type: Number, default: 5 },
});

const { postWidget } = useAdminWidgetApi();

const state = reactive({
    items: [],
    loading: false,
    total: 0,
    page: 1,
    rowsPerPage: props.rows,
    tooltip: { visible: false, x: 0, y: 0, item: null },
});

const totalPages = computed(() => Math.max(1, Math.ceil(state.total / state.rowsPerPage)));

function numberFormat(value) {
    return new Intl.NumberFormat("ru-RU").format(Number(value || 0));
}

function isCouponRecent(usedAt) {
    if (!usedAt) return false;
    const diffInDays = (Date.now() - new Date(usedAt).getTime()) / (1000 * 60 * 60 * 24);
    return diffInDays <= 30;
}

function hasLimits(obj) {
    return obj && Object.keys(obj).length > 0;
}

function openTooltip(item, event) {
    const rect = event.currentTarget.getBoundingClientRect();
    state.tooltip.visible = true;
    state.tooltip.item = item;
    state.tooltip.x = rect.left + rect.width / 2;
    state.tooltip.y = rect.bottom + 8;
}

function closeTooltip() {
    state.tooltip.visible = false;
    state.tooltip.item = null;
}

async function fetchData() {
    state.loading = true;
    try {
        const result = await postWidget(
            "/cw-page/widgets/last-registered",
            { rows: state.rowsPerPage },
            { page: state.page },
        );

        if (result?.success) {
            state.items = result.data?.data ?? [];
            state.total = result.data?.total ?? 0;
        } else {
            state.items = [];
            state.total = 0;
        }
    } finally {
        state.loading = false;
    }
}

function prevPage() {
    if (state.page > 1) {
        state.page--;
        fetchData();
    }
}

function nextPage() {
    if (state.page < totalPages.value) {
        state.page++;
        fetchData();
    }
}

function changeRows() {
    state.page = 1;
    fetchData();
}

onMounted(fetchData);
</script>

<template>
    <Card class="p-4">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-lg font-semibold">Последние регистрации</h3>
            <span class="text-sm text-muted-foreground">Показываем {{ state.rowsPerPage }} записей</span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-muted-foreground">
                        <th class="px-3 py-2">Подписчик</th>
                        <th class="px-3 py-2">Дата регистрации</th>
                        <th class="px-3 py-2">Подтверждён</th>
                        <th class="px-3 py-2">Тариф</th>
                        <th class="px-3 py-2">Баланс</th>
                        <th class="px-3 py-2">Лимиты</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in state.items" :key="item.id" class="border-b">
                        <td class="px-3 py-2">
                            <span class="inline-flex items-center">
                                <Link :href="`/cw-page/subscribers/${item.id}`" class="text-primary hover:underline">
                                    {{ item.name || "—" }}<span v-if="item.email"> ({{ item.email }})</span>
                                </Link>
                                <OAuthProviderBadge :vk-id="item.vk_id" :yandex-id="item.yandex_id" />
                            </span>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ item.registered_at || "—" }}</td>
                        <td class="px-3 py-2">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="item.is_verified ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'"
                            >
                                {{ item.is_verified ? "Да" : "Нет" }}
                            </span>
                        </td>
                        <td class="px-3 py-2">
                            <span>{{ item.plan?.name || "Нет" }}</span>
                            <span
                                v-if="item.coupon_usage?.coupon?.code && isCouponRecent(item.coupon_usage.used_at)"
                                class="ml-2 rounded-full bg-muted px-2 py-0.5 text-xs"
                            >
                                {{ item.coupon_usage.coupon.code }}
                            </span>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ numberFormat(item.balance ?? 0) }} ₽</td>
                        <td class="px-3 py-2">
                            <button
                                type="button"
                                class="text-primary hover:underline"
                                @mouseenter="openTooltip(item, $event)"
                                @mousemove="openTooltip(item, $event)"
                                @mouseleave="closeTooltip"
                            >
                                Лимиты ⓘ
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!state.items.length">
                        <td colspan="6" class="px-3 py-6 text-center text-muted-foreground">
                            {{ state.loading ? "Загрузка…" : "Нет данных" }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <WidgetPagination
            :page="state.page"
            :total-pages="totalPages"
            :per-page="state.rowsPerPage"
            :total="state.total"
            :loading="state.loading"
            @update:per-page="state.rowsPerPage = $event; changeRows()"
            @prev="prevPage"
            @next="nextPage"
        />

        <Teleport to="body">
            <div
                v-if="state.tooltip.visible"
                class="fixed z-50 w-72 max-w-[90vw] rounded-lg border bg-card p-3 text-sm shadow-lg"
                :style="{ left: `${state.tooltip.x}px`, top: `${state.tooltip.y}px`, transform: 'translateX(-50%)' }"
            >
                <div class="mb-2 font-semibold">Тарифные лимиты</div>
                <div v-if="hasLimits(state.tooltip.item?.limits_plan) || hasLimits(state.tooltip.item?.extra_limits_plan)">
                    <LimitList :base="state.tooltip.item?.limits_plan" :extra="state.tooltip.item?.extra_limits_plan" />
                </div>
                <div v-else class="mb-2 text-muted-foreground">Нет плановых лимитов</div>
                <div class="mb-2 font-semibold">Месячные лимиты</div>
                <div v-if="hasLimits(state.tooltip.item?.limits_month) || hasLimits(state.tooltip.item?.extra_limits_month)">
                    <LimitList :base="state.tooltip.item?.limits_month" :extra="state.tooltip.item?.extra_limits_month" />
                </div>
                <div v-else class="text-muted-foreground">Нет месячных лимитов</div>
            </div>
        </Teleport>
    </Card>
</template>