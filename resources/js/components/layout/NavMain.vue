<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import { SidebarGroup, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';

defineProps<{
    items: {
        title: string;
        url: string;
        icon: LucideIcon;
    }[];
}>();
</script>

<template>
    <SidebarGroup v-if="items.length">
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton as-child :tooltip="item.title">
                    <Link :href="item.url">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
