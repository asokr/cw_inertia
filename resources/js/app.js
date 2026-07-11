import "../css/app.css";
import { createInertiaApp } from "@inertiajs/vue3";
import { createApp, h } from "vue";
import { createPinia } from "pinia";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import { useAppColorMode } from "@/composables/useAppColorMode";

useAppColorMode();

createInertiaApp({
    title: (title) => (title ? `${title} — CW Platform` : "CW Platform"),
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob("./Pages/**/*.vue")),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(createPinia())
            .mount(el);
    },
    progress: {
        color: "#3b82f6",
    },
});