<script setup lang="ts">
import { cn } from '@/lib/utils';
import { reactiveOmit, useVModel } from '@vueuse/core';
import { SearchIcon } from '@lucide/vue';
import type { ListboxFilterEmits, ListboxFilterProps } from 'reka-ui';
import { ListboxFilter, useForwardProps } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { computed, watch } from 'vue';
import { useCommand } from '.';

defineOptions({
    inheritAttrs: false,
});

const props = defineProps<
    ListboxFilterProps & {
        class?: HTMLAttributes['class'];
    }
>();

const emits = defineEmits<ListboxFilterEmits>();

const delegatedProps = reactiveOmit(props, 'class');

const forwardedProps = useForwardProps(delegatedProps);

const { filterState } = useCommand();

const modelValue = useVModel(props, 'modelValue', emits, {
    defaultValue: '',
    passive: (props.modelValue === undefined) as false,
});

const inputValue = computed({
    get: () => modelValue.value,
    set: (value) => {
        modelValue.value = value;
        filterState.search = value ?? '';
    },
});

watch(
    () => modelValue.value,
    (value) => {
        if (value !== filterState.search) {
            filterState.search = value ?? '';
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="flex items-center border-b px-3" cmdk-input-wrapper>
        <SearchIcon class="mr-2 h-4 w-4 shrink-0 opacity-50" />
        <ListboxFilter
            v-bind="{ ...forwardedProps, ...$attrs }"
            v-model="inputValue"
            auto-focus
            :class="
                cn(
                    'flex h-10 w-full rounded-md bg-transparent py-3 text-sm outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50',
                    props.class,
                )
            "
        />
    </div>
</template>
