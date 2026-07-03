import { h } from "vue";
import TableRowActions from "@/components/admin/TableRowActions.vue";

/**
 * @param {Array<{ label: string, variant?: string, href?: string, target?: string, rel?: string, onClick?: () => void, disabled?: boolean }>} actions
 */
export function renderRowActions(actions) {
    const visible = actions.filter(Boolean);

    if (!visible.length) {
        return "—";
    }

    return h(TableRowActions, { actions: visible });
}

export const actionsColumn = {
    id: "actions",
    header: "Действия",
    enableSorting: false,
};