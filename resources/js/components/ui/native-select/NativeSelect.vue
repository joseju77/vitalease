<script lang="ts">
import type { AcceptableValue } from 'reka-ui';
import type { HTMLAttributes } from 'vue';

export type Props = {
    modelValue?: AcceptableValue | AcceptableValue[];
    class?: HTMLAttributes['class'];
    containerClass?: HTMLAttributes['class'];
    iconClass?: HTMLAttributes['class'];
};
</script>

<script setup lang="ts">
import { cn } from '@/lib/utils';
import { reactiveOmit, useVModel } from '@vueuse/core';
import { ChevronDownIcon } from '@lucide/vue';

defineOptions({
    inheritAttrs: false,
});

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:modelValue': AcceptableValue;
}>();

const modelValue = useVModel(props, 'modelValue', emit, {
    passive: true,
    defaultValue: '',
});

const delegatedProps = reactiveOmit(props, 'class', 'modelValue', 'containerClass', 'iconClass');
</script>

<template>
    <div :class="cn('group/native-select relative w-fit', props.containerClass)" data-slot="native-select-wrapper">
        <select
            v-bind="{ ...$attrs, ...delegatedProps }"
            v-model="modelValue"
            data-slot="native-select"
            :class="
                cn(
                    'disabled:pointer-events-nonedark:bg-input/30 h-9 w-full min-w-0 appearance-none rounded-md border border-input bg-transparent px-3 py-2 pr-9 text-sm shadow-xs transition-[color,box-shadow] outline-none selection:bg-primary selection:text-primary-foreground placeholder:text-muted-foreground disabled:cursor-not-allowed dark:hover:bg-input/50',
                    'aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40',
                    'hover:cursor-pointer hover:group-data-[disabled=false]/field:bg-accent',
                    'hover:group-data-[disabled=false]/field:group-data-[invalid=true]/field:bg-destructive/15',
                    props.class,
                )
            "
        >
            <slot />
        </select>
        <ChevronDownIcon
            :class="
                cn(
                    'pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 stroke-current text-muted-foreground select-none',
                    props.iconClass,
                )
            "
            aria-hidden="true"
            data-slot="native-select-icon"
        />
    </div>
</template>
