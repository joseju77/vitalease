<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { useVModel } from '@vueuse/core';
import { cn } from '@/lib/utils';

const props = defineProps<{ modelValue?: string; class?: HTMLAttributes['class'] }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();
const modelValue = useVModel(props, 'modelValue', emit, { passive: true });
</script>

<template>
    <textarea
        v-model="modelValue"
        data-slot="textarea"
        :class="
            cn(
                'border-input focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:border-destructive min-h-20 w-full rounded-lg border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-3 disabled:opacity-50',
                props.class,
            )
        "
    />
</template>
