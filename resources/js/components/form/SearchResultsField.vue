<script setup lang="ts">
import { onClickOutside } from '@vueuse/core';
import type { HTMLAttributes } from 'vue';
import { computed, ref } from 'vue';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type SearchOption = {
    value: string;
    label: string;
    description?: string | null;
};

const props = withDefaults(
    defineProps<{
        id?: string;
        label: string;
        placeholder: string;
        options: SearchOption[];
        loading: boolean;
        searched: boolean;
        minQueryLength?: number;
        emptyMessage: string;
        loadingMessage: string;
        inputClass?: HTMLAttributes['class'];
    }>(),
    { minQueryLength: 2 },
);

const emit = defineEmits<{ select: [value: string] }>();
const slots = defineSlots<{ leading?: () => unknown }>();
const query = defineModel<string>({ default: '' });
const root = ref<HTMLElement | null>(null);
const open = ref(false);
const inputId = props.id ?? `search-results-${Math.random().toString(36).slice(2, 9)}`;
const resultsId = `${inputId}-results`;
const showResults = computed(() => open.value && query.value.trim().length >= props.minQueryLength);

onClickOutside(root, () => {
    open.value = false;
});

function select(value: string): void {
    open.value = false;
    emit('select', value);
}
</script>

<template>
    <div ref="root" class="relative">
        <Field>
            <FieldLabel :for="inputId">{{ label }}</FieldLabel>
            <div class="relative">
                <span
                    v-if="slots.leading"
                    class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-muted-foreground [&_svg]:size-4"
                    aria-hidden="true"
                >
                    <slot name="leading" />
                </span>
                <Input
                    :id="inputId"
                    :class="cn(slots.leading && 'pl-9', inputClass)"
                    :model-value="query"
                    :placeholder="placeholder"
                    :aria-expanded="showResults"
                    :aria-controls="showResults ? resultsId : undefined"
                    autocomplete="off"
                    @update:model-value="
                        query = String($event);
                        open = true;
                    "
                    @focus="open = true"
                    @keydown.esc="open = false"
                />
            </div>
        </Field>
        <div
            v-if="showResults"
            :id="resultsId"
            class="absolute top-full z-50 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border bg-popover p-1 text-popover-foreground shadow-md"
        >
            <p v-if="loading || !searched" class="px-3 py-2 text-sm text-muted-foreground" role="status">
                {{ loadingMessage }}
            </p>
            <p v-else-if="options.length === 0" class="px-3 py-2 text-sm text-muted-foreground" role="status">
                {{ emptyMessage }}
            </p>
            <button
                v-for="option in searched && !loading ? options : []"
                :key="option.value"
                type="button"
                class="flex w-full flex-col rounded-md px-3 py-2 text-left text-sm outline-none hover:bg-accent focus-visible:bg-accent"
                @click="select(option.value)"
            >
                <span class="font-medium">{{ option.label }}</span>
                <span v-if="option.description" class="text-xs text-muted-foreground">{{ option.description }}</span>
            </button>
        </div>
    </div>
</template>
