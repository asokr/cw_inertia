import { computed, onMounted, onUnmounted, ref, unref, watch } from "vue";

const DEFAULT_COOLDOWN_MS = 60_000;

/**
 * Client-side cooldown for WB API rate limits (1 request per minute).
 *
 * @param {import('vue').MaybeRefOrGetter<number|string|null|undefined>} scopeId
 * @param {number} [cooldownMs]
 */
export function useWbApiRateLimitCooldown(scopeId, cooldownMs = DEFAULT_COOLDOWN_MS) {
    const rateLimitUntil = ref(0);
    const now = ref(Date.now());
    let timer = null;

    const storageKey = computed(() => {
        const id = unref(scopeId);
        return id == null ? null : `wb-api-rate-limit-${id}`;
    });

    function syncNow() {
        now.value = Date.now();
    }

    function restore() {
        const key = storageKey.value;
        if (!key) {
            return;
        }

        const stored = sessionStorage.getItem(key);
        if (!stored) {
            return;
        }

        const until = Number(stored);
        rateLimitUntil.value = Number.isFinite(until) ? until : 0;

        if (rateLimitUntil.value <= now.value) {
            sessionStorage.removeItem(key);
            rateLimitUntil.value = 0;
        }
    }

    function start() {
        const key = storageKey.value;
        if (!key) {
            return;
        }

        rateLimitUntil.value = Date.now() + cooldownMs;
        sessionStorage.setItem(key, String(rateLimitUntil.value));
        syncNow();
    }

    const isActive = computed(() => rateLimitUntil.value > now.value);

    watch(storageKey, () => {
        restore();
    });

    onMounted(() => {
        restore();
        timer = window.setInterval(syncNow, 1000);
    });

    onUnmounted(() => {
        if (timer) {
            window.clearInterval(timer);
        }
    });

    return {
        isActive,
        start,
    };
}