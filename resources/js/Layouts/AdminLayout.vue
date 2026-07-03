<script setup>
import { Link, router, usePage } from "@inertiajs/vue3";
import { useDark, useToggle } from "@vueuse/core";
import {
    ChevronDown,
    LogOut,
    Menu,
    Moon,
    Sun,
    X,
} from "lucide-vue-next";
import { computed, ref } from "vue";
import AiCostsWidget from "@/components/admin/AiCostsWidget.vue";
import FlashToasts from "@/components/admin/FlashToasts.vue";
import Button from "@/components/ui/Button.vue";
import { getAdminNav, getDefaultAdminPath } from "@/config/adminNav";
import { usePermissions } from "@/composables/usePermissions";

defineProps({
    title: { type: String, default: "" },
    breadcrumbs: { type: Array, default: () => [] },
});

const page = usePage();
const { can, hasRole } = usePermissions();
const mobileOpen = ref(false);
const expandedGroups = ref({});
const isDark = useDark();
const toggleDark = useToggle(isDark);

const user = computed(() => page.props.auth?.user);
const navGroups = computed(() => getAdminNav({ can, hasRole }));
const defaultAdminPath = computed(() => getDefaultAdminPath());
const isSuperAdmin = computed(() => can("super admin") || hasRole("Супер-Админ") || hasRole("super-admin"));

function toggleGroup(label) {
    expandedGroups.value[label] = !expandedGroups.value[label];
}

function logout() {
    router.post("/logout");
}

function isActive(href) {
    return page.url === href || page.url.startsWith(href + "/");
}
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <FlashToasts />
        <div class="mx-auto flex min-h-screen w-full max-w-[1920px]">
            <aside class="hidden w-60 shrink-0 border-r bg-card md:flex md:flex-col">
                <div class="flex h-14 items-center border-b px-4">
                    <Link :href="defaultAdminPath" class="text-sm font-semibold tracking-tight">CW Platform</Link>
                </div>
                <nav class="flex-1 overflow-y-auto p-3">
                    <div v-for="group in navGroups" :key="group.label" class="mb-3">
                        <p class="mb-1 px-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            {{ group.label }}
                        </p>
                        <template v-for="item in group.items" :key="item.label">
                            <div v-if="item.children">
                                <button
                                    type="button"
                                    class="flex w-full items-center justify-between rounded-md px-3 py-2 text-sm text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                                    @click="toggleGroup(item.label)"
                                >
                                    <span class="flex items-center gap-2">
                                        <component :is="item.icon" v-if="item.icon" class="h-4 w-4" />
                                        {{ item.label }}
                                    </span>
                                    <ChevronDown class="h-3 w-3" />
                                </button>
                                <div v-show="expandedGroups[item.label]" class="ml-4 mt-1 space-y-1">
                                    <Link
                                        v-for="child in item.children"
                                        :key="child.href"
                                        :href="child.href"
                                        class="block rounded-md px-3 py-1.5 text-sm hover:bg-accent"
                                        :class="isActive(child.href) ? 'bg-accent font-medium text-foreground' : 'text-muted-foreground'"
                                    >
                                        {{ child.label }}
                                    </Link>
                                </div>
                            </div>
                            <Link
                                v-else
                                :href="item.href"
                                class="flex items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-accent hover:text-accent-foreground"
                                :class="isActive(item.href) ? 'bg-accent font-medium text-foreground' : 'text-muted-foreground'"
                            >
                                <component :is="item.icon" v-if="item.icon" class="h-4 w-4" />
                                {{ item.label }}
                            </Link>
                        </template>
                    </div>
                </nav>
            </aside>

            <div v-if="mobileOpen" class="fixed inset-0 z-40 bg-black/40 md:hidden" @click="mobileOpen = false" />

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
                        <AiCostsWidget v-if="isSuperAdmin" />
                        <Button variant="ghost" size="icon" @click="toggleDark()">
                            <Sun v-if="isDark" class="h-4 w-4" />
                            <Moon v-else class="h-4 w-4" />
                        </Button>
                        <div class="hidden text-right text-xs sm:block">
                            <div class="font-medium">{{ user?.name }}</div>
                            <div class="text-muted-foreground">{{ user?.email }}</div>
                        </div>
                        <Button variant="outline" size="sm" @click="logout">
                            <LogOut class="mr-1 h-4 w-4" />
                            Выйти
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