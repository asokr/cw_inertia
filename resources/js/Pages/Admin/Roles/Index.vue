<script setup>
import { Head, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { actionsColumn, renderRowActions } from "@/lib/tableActions";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import ManagementSubnav from "@/components/admin/ManagementSubnav.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Checkbox from "@/components/ui/Checkbox.vue";
import Badge from "@/components/ui/Badge.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Tabs from "@/components/ui/Tabs.vue";
import TabsList from "@/components/ui/TabsList.vue";
import TabsTrigger from "@/components/ui/TabsTrigger.vue";
import TabsContent from "@/components/ui/TabsContent.vue";

const props = defineProps({
    users: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    permissions: { type: Array, default: () => [] },
});

const activeTab = ref("users");
const search = ref("");
const userDialogOpen = ref(false);
const roleDialogOpen = ref(false);
const selectedUser = ref(null);
const editingRole = ref(null);

const accessForm = useForm({ roles: [], permissions: [] });
const roleForm = useForm({ name: "", permissions: [] });

const filteredUsers = computed(() => {
    const term = search.value.toLowerCase().trim();
    if (!term) return props.users;

    return props.users.filter((user) => {
        const byName = `${user.full_name || ""} ${user.name || ""} ${user.surname || ""}`.toLowerCase().includes(term);
        const byEmail = (user.email || "").toLowerCase().includes(term);
        const byRoles = (user.roles || []).some((r) => (r.name || "").toLowerCase().includes(term));
        const byPerms = (user.permissions || []).some((p) => (p.name || "").toLowerCase().includes(term));
        return byName || byEmail || byRoles || byPerms;
    });
});

const userColumns = [
    {
        accessorKey: "name",
        header: "Пользователь",
        cell: ({ row }) => row.original.full_name || row.original.name,
    },
    {
        id: "roles",
        header: "Роли",
        cell: ({ row }) => (row.original.roles ?? []).map((r) => r.name).join(", ") || "—",
    },
    {
        id: "perms",
        header: "Персональные разрешения",
        cell: ({ row }) => {
            const perms = row.original.permissions ?? [];
            if (!perms.length) return "—";
            return perms.slice(0, 3).map((p) => p.name).join(", ") + (perms.length > 3 ? ` +${perms.length - 3}` : "");
        },
    },
    {
        ...actionsColumn,
        cell: ({ row }) => renderRowActions([
            { label: "Доступ", onClick: () => openUserDialog(row.original) },
        ]),
    },
];

function openUserDialog(user) {
    selectedUser.value = user;
    accessForm.roles = (user.roles ?? []).map((r) => r.id);
    accessForm.permissions = (user.permissions ?? []).map((p) => p.id);
    userDialogOpen.value = true;
}

function saveUserAccess() {
    if (!selectedUser.value) return;
    accessForm.put(`/cw-page/roles/users/${selectedUser.value.id}/access`, {
        onSuccess: () => { userDialogOpen.value = false; },
    });
}

function openRoleCreate() {
    editingRole.value = null;
    roleForm.reset();
    roleDialogOpen.value = true;
}

function openRoleEdit(role) {
    editingRole.value = role;
    roleForm.name = role.name;
    roleForm.permissions = (role.permissions ?? []).map((p) => p.id);
    roleDialogOpen.value = true;
}

function toggleRolePermission(id, checked) {
    const idx = roleForm.permissions.indexOf(id);
    if (checked && idx < 0) roleForm.permissions.push(id);
    if (!checked && idx >= 0) roleForm.permissions.splice(idx, 1);
}

function toggleAccessRole(id, checked) {
    const idx = accessForm.roles.indexOf(id);
    if (checked && idx < 0) accessForm.roles.push(id);
    if (!checked && idx >= 0) accessForm.roles.splice(idx, 1);
}

function toggleAccessPermission(id, checked) {
    const idx = accessForm.permissions.indexOf(id);
    if (checked && idx < 0) accessForm.permissions.push(id);
    if (!checked && idx >= 0) accessForm.permissions.splice(idx, 1);
}

function saveRole() {
    if (editingRole.value) {
        roleForm.put(`/cw-page/roles/${editingRole.value.id}`, {
            onSuccess: () => { roleDialogOpen.value = false; },
        });
    } else {
        roleForm.post("/cw-page/roles", {
            onSuccess: () => { roleDialogOpen.value = false; roleForm.reset(); },
        });
    }
}

function deleteRole(role) {
    if (!confirm(`Удалить роль «${role.name}»?`)) return;
    roleForm.delete(`/cw-page/roles/${role.id}`);
}
</script>

<template>
    <Head title="Роли и доступ" />

    <AdminLayout title="Роли" :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Роли' }]">
        <PageHeader title="Управление доступом" description="Роли, разрешения и персональные права пользователей" />

        <ManagementSubnav />

        <Tabs v-model="activeTab" default-value="users">
            <TabsList class="mb-4">
                <TabsTrigger value="users">Пользователи</TabsTrigger>
                <TabsTrigger value="roles">Роли системы</TabsTrigger>
            </TabsList>

            <TabsContent value="users">
                <Card class="mb-4 p-4">
                    <Input v-model="search" placeholder="Поиск по имени, email, ролям…" class="max-w-md" />
                </Card>
                <Card class="p-4">
                    <DataTable :columns="userColumns" :data="filteredUsers" />
                </Card>
            </TabsContent>

            <TabsContent value="roles">
                <div class="mb-4">
                    <Button @click="openRoleCreate">Создать роль</Button>
                </div>
                <div class="space-y-3">
                    <Card v-for="role in roles" :key="role.id" class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold">{{ role.name }}</h3>
                                <div class="mt-2 flex flex-wrap gap-1">
                                    <Badge v-for="perm in (role.permissions ?? []).slice(0, 8)" :key="perm.id" variant="secondary">
                                        {{ perm.name }}
                                    </Badge>
                                    <Badge v-if="(role.permissions ?? []).length > 8" variant="outline">
                                        +{{ role.permissions.length - 8 }}
                                    </Badge>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" @click="openRoleEdit(role)">Редактировать</Button>
                                <Button variant="destructive" size="sm" @click="deleteRole(role)">Удалить</Button>
                            </div>
                        </div>
                    </Card>
                </div>
            </TabsContent>
        </Tabs>

        <Dialog v-model:open="userDialogOpen" title="Редактирование доступа">
            <div v-if="selectedUser" class="space-y-4">
                <p class="text-sm text-muted-foreground">{{ selectedUser.full_name || selectedUser.name }} — {{ selectedUser.email }}</p>
                <div>
                    <p class="mb-2 text-sm font-medium">Роли</p>
                    <div class="max-h-40 space-y-2 overflow-y-auto">
                        <label v-for="role in roles" :key="'ur-' + role.id" class="flex items-center gap-2 text-sm">
                            <Checkbox
                                :model-value="accessForm.roles.includes(role.id)"
                                @update:model-value="toggleAccessRole(role.id, $event)"
                            />
                            {{ role.name }}
                        </label>
                    </div>
                </div>
                <div>
                    <p class="mb-2 text-sm font-medium">Персональные разрешения</p>
                    <div class="max-h-48 space-y-2 overflow-y-auto">
                        <label v-for="perm in permissions" :key="'up-' + perm.id" class="flex items-center gap-2 text-sm">
                            <Checkbox
                                :model-value="accessForm.permissions.includes(perm.id)"
                                @update:model-value="toggleAccessPermission(perm.id, $event)"
                            />
                            {{ perm.name }}
                        </label>
                    </div>
                </div>
            </div>
            <template #footer>
                <Button variant="outline" @click="userDialogOpen = false">Отмена</Button>
                <Button :disabled="accessForm.processing" @click="saveUserAccess">Сохранить</Button>
            </template>
        </Dialog>

        <Dialog v-model:open="roleDialogOpen" :title="editingRole ? 'Редактировать роль' : 'Новая роль'">
            <div class="space-y-3">
                <Input v-model="roleForm.name" placeholder="Название роли" />
                <div class="max-h-56 space-y-2 overflow-y-auto">
                    <label v-for="perm in permissions" :key="'rp-' + perm.id" class="flex items-center gap-2 text-sm">
                        <Checkbox
                            :model-value="roleForm.permissions.includes(perm.id)"
                            @update:model-value="toggleRolePermission(perm.id, $event)"
                        />
                        {{ perm.name }}
                    </label>
                </div>
            </div>
            <template #footer>
                <Button variant="outline" @click="roleDialogOpen = false">Отмена</Button>
                <Button :disabled="roleForm.processing" @click="saveRole">Сохранить</Button>
            </template>
        </Dialog>
    </AdminLayout>
</template>