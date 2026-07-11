import { useDark } from "@vueuse/core";

let isDark = null;

export function useAppColorMode() {
    if (!isDark) {
        isDark = useDark({
            selector: "html",
            attribute: "class",
            valueDark: "dark",
            valueLight: "",
            storageKey: "cw-color-scheme",
            initialValue: "light",
        });
    }

    return isDark;
}

export function toggleAppColorMode() {
    const mode = useAppColorMode();
    mode.value = !mode.value;
}