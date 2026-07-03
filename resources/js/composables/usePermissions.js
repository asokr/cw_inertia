import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

export function usePermissions() {
    const page = usePage();

    const permissions = computed(() => page.props.auth?.permissions ?? []);
    const roles = computed(() => page.props.auth?.roles ?? []);

    const isSuperAdmin = computed(
        () =>
            roles.value.includes("super-admin")
            || roles.value.includes("Супер-Админ")
            || permissions.value.includes("super admin"),
    );

    const isAdmin = computed(
        () =>
            isSuperAdmin.value
            || permissions.value.includes("blog.view")
            || permissions.value.includes("administrator"),
    );

    const can = (permission) => isAdmin.value || permissions.value.includes(permission);
    const hasRole = (role) => roles.value.includes(role);

    return {
        permissions,
        roles,
        isSuperAdmin,
        isAdmin,
        can,
        hasRole,
    };
}