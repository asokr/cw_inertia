<script setup>
import { Link, router, usePage } from "@inertiajs/vue3";
import { useDark, useToggle } from "@vueuse/core";
import {
    ChevronDown,
    LogOut,
    Menu,
    Moon,
    Sun,
    User,
    X,
} from "lucide-vue-next";
import { computed, ref } from "vue";
import FlashToasts from "@/components/admin/FlashToasts.vue";
import TopBalance from "@/components/subscriber/TopBalance.vue";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";
import { getSubscriberNav } from "@/config/subscriberNav";
import { usePermissions } from "@/composables/usePermissions";

/**
 * breadcrumbs: [{ label: string, href?: string }]
 * Example: [{ label: 'Wildberries', href: '/panel/wb/feedbacks' }, { label: 'Кабинет' }]
 */
defineProps({
    title: { type: String, default: "" },
    breadcrumbs: { type: Array, default: () => [] },
});

const page = usePage();
const { can, hasRole, isAdmin } = usePermissions();
const mobileOpen = ref(false);
const expandedGroups = ref({});
const isDark = useDark();
const toggleDark = useToggle(isDark);

const user = computed(() => page.props.auth?.user);
const nav = computed(() => getSubscriberNav({ can, hasRole, isAdmin: isAdmin.value }));

function isGroupActive(item) {
    if (!item?.children?.length) {
        return false;
    }

    return item.children.some((child) => !child.comingSoon && isActive(child.href));
}

function isGroupExpanded(item) {
    if (isGroupActive(item)) {
        return true;
    }

    return !!expandedGroups.value[item.label];
}

function toggleGroup(item) {
    expandedGroups.value[item.label] = !isGroupExpanded(item);
}

function logout() {
    router.post("/logout");
}

function isActive(href) {
    if (href === "/panel") {
        return page.url === "/panel";
    }

    return page.url === href || page.url.startsWith(href + "/");
}

function navLinkClass(href, comingSoon) {
    if (comingSoon) {
        return "flex items-center justify-between rounded-md px-3 py-2 text-sm text-muted-foreground/60 cursor-not-allowed";
    }

    return [
        "flex items-center justify-between rounded-md px-3 py-2 text-sm hover:bg-accent hover:text-accent-foreground",
        isActive(href) ? "bg-accent font-medium text-foreground" : "text-muted-foreground",
    ];
}
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <FlashToasts />
        <div class="mx-auto flex min-h-screen w-full max-w-[1920px]">
            <aside class="hidden w-60 shrink-0 border-r bg-card md:flex md:flex-col">
                <div class="flex h-14 items-center border-b px-4">
                    <Link href="/panel" class="text-sm font-semibold tracking-tight">CW Platform</Link>
                </div>

                <div class="border-b px-4 py-3 text-sm text-muted-foreground">
                    Привет, <span class="font-medium text-foreground">{{ user?.name }}</span>
                </div>

                <nav class="flex-1 overflow-y-auto p-3">
                    <template v-for="item in nav.main" :key="item.label">
                        <div v-if="item.children" class="mb-1">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between rounded-md px-3 py-2 text-sm hover:bg-accent hover:text-accent-foreground"
                                :class="isGroupActive(item) ? 'font-medium text-foreground' : 'text-muted-foreground'"
                                @click="toggleGroup(item)"
                            >
                                <span class="flex items-center gap-2">
                                    <component :is="item.icon" v-if="item.icon" class="h-4 w-4" />
                                    {{ item.label }}
                                </span>
                                <ChevronDown
                                    class="h-3 w-3 transition-transform"
                                    :class="isGroupExpanded(item) && 'rotate-180'"
                                />
                            </button>
                            <div v-show="isGroupExpanded(item)" class="ml-4 mt-1 space-y-1">
                                <template v-for="child in item.children" :key="child.label">
                                    <span
                                        v-if="child.comingSoon"
                                        :class="navLinkClass(child.href, true)"
                                    >
                                        <span>{{ child.label }}</span>
                                        <Badge variant="secondary" class="text-[10px]">скоро</Badge>
                                    </span>
                                    <Link
                                        v-else
                                        :href="child.href"
                                        :class="navLinkClass(child.href, false)"
                                    >
                                        {{ child.label }}
                                    </Link>
                                </template>
                            </div>
                        </div>
                        <template v-else>
                            <span
                                v-if="item.comingSoon"
                                :class="navLinkClass(item.href, true)"
                            >
                                <span class="flex items-center gap-2">
                                    <component :is="item.icon" v-if="item.icon" class="h-4 w-4" />
                                    {{ item.label }}
                                </span>
                                <Badge variant="secondary" class="text-[10px]">скоро</Badge>
                            </span>
                            <Link
                                v-else
                                :href="item.href"
                                :class="navLinkClass(item.href, false)"
                            >
                                <span class="flex items-center gap-2">
                                    <component :is="item.icon" v-if="item.icon" class="h-4 w-4" />
                                    {{ item.label }}
                                </span>
                            </Link>
                        </template>
                    </template>
                </nav>

                <div class="border-t p-3">
                    <template v-for="item in nav.bottom" :key="item.label">
                        <span
                            v-if="item.comingSoon"
                            class="mb-1 flex items-center justify-between rounded-md px-3 py-2 text-sm text-muted-foreground/60"
                        >
                            <span class="flex items-center gap-2">
                                <component :is="item.icon" v-if="item.icon" class="h-4 w-4" />
                                {{ item.label }}
                            </span>
                            <Badge variant="secondary" class="text-[10px]">скоро</Badge>
                        </span>
                    </template>

                    <Link
                        href="/panel/user/profile"
                        class="mb-2 flex items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-accent"
                        :class="isActive('/panel/user/profile') ? 'bg-accent font-medium' : 'text-muted-foreground'"
                    >
                        <User class="h-4 w-4" />
                        Профиль
                    </Link>

                    <Button variant="outline" class="w-full" @click="logout">
                        <LogOut class="mr-2 h-4 w-4" />
                        Выйти
                    </Button>
                </div>
            </aside>

            <div v-if="mobileOpen" class="fixed inset-0 z-40 bg-black/40 md:hidden" @click="mobileOpen = false" />
            <aside
                class="fixed inset-y-0 left-0 z-50 w-64 border-r bg-card p-4 transition-transform md:hidden"
                :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
            >
                <div class="mb-4 flex items-center justify-between">
                    <Link href="/panel" class="text-sm font-semibold">CW Platform</Link>
                    <button type="button" @click="mobileOpen = false">
                        <X class="h-5 w-5" />
                    </button>
                </div>
                <nav class="space-y-1">
                    <Link
                        href="/panel"
                        class="block rounded-md px-3 py-2 text-sm hover:bg-accent"
                        @click="mobileOpen = false"
                    >
                        Главная
                    </Link>
                    <Link
                        href="/panel/user/profile"
                        class="block rounded-md px-3 py-2 text-sm hover:bg-accent"
                        @click="mobileOpen = false"
                    >
                        Профиль
                    </Link>
                </nav>
            </aside>

            <div class="flex min-w-0 flex-1 flex-col">
                <header class="flex h-14 items-center justify-between border-b px-4">
                    <div class="flex items-center gap-3">
                        <button type="button" class="md:hidden" @click="mobileOpen = true">
                            <Menu class="h-5 w-5" />
                        </button>
                        <div>
                            <h1 v-if="title" class="text-sm font-semibold">{{ title }}</h1>
                            <div v-if="breadcrumbs.length" class="flex items-center gap-1 text-xs text-muted-foreground">
                                <template v-for="(crumb, index) in breadcrumbs" :key="crumb.label">
                                    <span v-if="index > 0">/</span>
                                    <Link v-if="crumb.href" :href="crumb.href" class="hover:text-foreground">{{ crumb.label }}</Link>
                                    <span v-else>{{ crumb.label }}</span>
                                </template>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <TopBalance />
                        <Link
                            v-if="isAdmin"
                            href="/cw-page"
                            class="hidden text-xs text-muted-foreground hover:text-foreground sm:block"
                        >
                            Админка
                        </Link>
                        <Button variant="ghost" size="icon" @click="toggleDark()">
                            <Sun v-if="isDark" class="h-4 w-4" />
                            <Moon v-else class="h-4 w-4" />
                        </Button>
                    </div>
                </header>
                <main class="flex-1 p-4 md:p-6">
                    <slot />
                </main>
            </div>
        </div>
    </div>
</template>