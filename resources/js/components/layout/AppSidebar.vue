<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ShieldCheck, Stethoscope, Users } from '@lucide/vue';
import { computed } from 'vue';
import NavMain from '@/components/layout/NavMain.vue';
import NavUser from '@/components/layout/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarRail,
} from '@/components/ui/sidebar';
import { usePermissions } from '@/composables/usePermissions';
import { index as dashboardIndex } from '@/routes/dashboard';
import { index as rolesIndex } from '@/routes/roles';
import { index as usersIndex } from '@/routes/users';
import type { AppPageProps } from '@/types';
import appLogo from '@resources/img/logos/vitalease.svg';

const page = usePage<AppPageProps>();
const { can } = usePermissions();

const navMain = computed(() => [
    ...(can('consultations.view') ? [{ title: 'Consultas', url: dashboardIndex().url, icon: Stethoscope }] : []),
    ...(can('users.manage') ? [{ title: 'Usuarios', url: usersIndex().url, icon: Users }] : []),
    ...(can('roles.manage') ? [{ title: 'Roles', url: rolesIndex().url, icon: ShieldCheck }] : []),
]);
</script>

<template>
    <Sidebar variant="inset" collapsible="icon">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link href="/">
                            <img :src="appLogo" alt="Logo de VitalEase" class="size-8 rounded-lg" />
                            <div class="grid flex-1 text-left text-sm leading-tight">
                                <span class="truncate font-semibold">VitalEase</span>
                            </div>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>
        <SidebarContent>
            <NavMain :items="navMain" />
        </SidebarContent>
        <SidebarFooter>
            <NavUser v-if="page.props.auth.user" :user="page.props.auth.user" />
        </SidebarFooter>
        <SidebarRail />
    </Sidebar>
</template>
