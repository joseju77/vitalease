<script setup lang="ts">
import AppSidebar from '@/components/layout/AppSidebar.vue';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import { Toaster } from '@/components/ui/sonner';
import 'vue-sonner/style.css';

defineProps<{
    title?: string;
}>();
</script>

<template>
    <SidebarProvider>
        <AppSidebar />
        <SidebarInset>
            <header class="flex h-14 shrink-0 items-center gap-2 border-b border-border/70 px-4">
                <SidebarTrigger class="-ml-1" />
                <Separator orientation="vertical" class="mr-2 data-[orientation=vertical]:h-4 my-auto" />
                <Breadcrumb>
                    <BreadcrumbList>
                        <BreadcrumbItem>
                            <BreadcrumbLink v-if="title" href="/">VitalEase</BreadcrumbLink>
                            <BreadcrumbPage v-else>VitalEase</BreadcrumbPage>
                        </BreadcrumbItem>
                        <template v-if="title">
                            <BreadcrumbSeparator />
                            <BreadcrumbItem>
                                <BreadcrumbPage>{{ title }}</BreadcrumbPage>
                            </BreadcrumbItem>
                        </template>
                    </BreadcrumbList>
                </Breadcrumb>
            </header>

            <main class="flex flex-1 flex-col gap-4 p-6">
                <slot />
            </main>
        </SidebarInset>

        <Toaster />
    </SidebarProvider>
</template>
