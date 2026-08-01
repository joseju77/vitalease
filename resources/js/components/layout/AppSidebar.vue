<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
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
import { NAV_GROUPS, visibleNavGroups } from '@/lib/navigation';
import { home } from '@/routes';
import type { AppPageProps } from '@/types';
import appLogo from '@resources/img/logos/vitalease.svg';

const page = usePage<AppPageProps>();
const { can } = usePermissions();

const navGroups = computed(() => visibleNavGroups(NAV_GROUPS, can));
</script>

<template>
    <Sidebar variant="inset" collapsible="icon">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="home().url">
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
            <NavMain :groups="navGroups" />
        </SidebarContent>
        <SidebarFooter>
            <NavUser v-if="page.props.auth.user" :user="page.props.auth.user" />
        </SidebarFooter>
        <SidebarRail />
    </Sidebar>
</template>
