import { nextTick } from "vue";
import { router } from "@inertiajs/vue3";

function isHomePage() {
    const path = window.location.pathname;

    return path === "/" || path === "";
}

export function useLandingSectionNav() {
    function scrollToSection(sectionId) {
        const element = document.getElementById(sectionId);

        if (!element) {
            return false;
        }

        element.scrollIntoView({ behavior: "smooth", block: "start" });
        history.replaceState(null, "", `#${sectionId}`);

        return true;
    }

    function navigateToSection(sectionId, event) {
        event?.preventDefault();

        if (isHomePage()) {
            scrollToSection(sectionId);
            return;
        }

        router.visit("/", {
            onSuccess: () => {
                nextTick(() => {
                    requestAnimationFrame(() => {
                        scrollToSection(sectionId);
                    });
                });
            },
        });
    }

    function scrollToHashFromUrl() {
        const hash = window.location.hash.replace(/^#/, "");

        if (!hash || !isHomePage()) {
            return;
        }

        requestAnimationFrame(() => {
            scrollToSection(hash);
        });
    }

    return {
        navigateToSection,
        scrollToSection,
        scrollToHashFromUrl,
    };
}