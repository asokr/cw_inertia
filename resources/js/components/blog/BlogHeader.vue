<script setup>
import { Link, usePage } from "@inertiajs/vue3";
import { Menu, X } from "lucide-vue-next";
import { computed, onMounted, onUnmounted, ref } from "vue";
import logo from "@/assets/blog/logo.svg";

const page = usePage();
const mobileOpen = ref(false);
const isDesktop = ref(false);

const isAuthenticated = computed(() => Boolean(page.props.auth?.user));

const navItems = [
    { label: "Инструменты", comingSoon: true },
    { label: "Тарифы", comingSoon: true },
    { label: "Вопрос/Ответ", comingSoon: true },
    { label: "Блог", href: "/blog", comingSoon: false },
    { label: "Поддержка", comingSoon: true },
];

function updateWidth() {
    isDesktop.value = window.innerWidth >= 768;

    if (isDesktop.value) {
        mobileOpen.value = true;
    } else {
        mobileOpen.value = false;
    }
}

onMounted(() => {
    updateWidth();
    window.addEventListener("resize", updateWidth);
});

onUnmounted(() => {
    window.removeEventListener("resize", updateWidth);
});
</script>

<template>
    <header class="blog-header">
        <div class="blog-wrapper">
            <div class="blog-header-block">
                <div class="blog-header-logo">
                    <Link href="/blog">
                        <img :src="logo" alt="CW Platform">
                    </Link>
                </div>

                <button
                    type="button"
                    class="blog-header-burger"
                    aria-label="Меню"
                    @click="mobileOpen = !mobileOpen"
                >
                    <Menu v-if="!mobileOpen" class="h-5 w-5" />
                    <X v-else class="h-5 w-5" />
                </button>

                <div v-show="mobileOpen || isDesktop" class="blog-header-menu-block">
                    <ul class="blog-header-menu">
                        <li v-for="item in navItems" :key="item.label">
                            <Link
                                v-if="item.href && !item.comingSoon"
                                :href="item.href"
                                class="blog-header-menu__link"
                            >
                                {{ item.label }}
                            </Link>
                            <span v-else class="blog-header-menu__link blog-header-menu__link--disabled">
                                {{ item.label }}
                                <span v-if="item.comingSoon" class="blog-header-menu__soon">скоро</span>
                            </span>
                        </li>
                    </ul>
                </div>

                <div class="blog-header-btns">
                    <template v-if="isAuthenticated">
                        <Link href="/panel" class="blog-btn">Панель</Link>
                    </template>
                    <template v-else>
                        <Link href="/login" class="blog-btn">Вход</Link>
                        <Link href="/register" class="blog-btn blog-btn-register">Регистрация</Link>
                    </template>
                </div>
            </div>
        </div>
    </header>
</template>