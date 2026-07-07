<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { reactiveOmit, useVModel } from '@vueuse/core';
import { CheckIcon, ChevronsUpDownIcon } from '@lucide/vue';
import type { AcceptableValue, FocusOutsideEvent, ListboxRootProps, PointerDownOutsideEvent } from 'reka-ui';
import { computed, HTMLAttributes, ref, useAttrs, useTemplateRef } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type ComboboxOption = {
    value: AcceptableValue;
    label: string;
    disabled?: boolean;
    class?: HTMLAttributes['class'];
};

type ComboboxOptionGroup = {
    label: string;
    options: ComboboxOption[];
};

type BaseField = {
    id?: string;
    modelValue?: AcceptableValue | AcceptableValue[];
    label?: string;
    errors?: string[] | string | undefined;
    subLabel?: string;
    class?: HTMLAttributes['class'];
    disabled?: boolean;
    multiple?: boolean;
    required?: boolean;
    hideRequiredAsterisk?: boolean;
};

const props = withDefaults(
    defineProps<
        BaseField &
            ListboxRootProps & {
                options: ComboboxOption[] | ComboboxOptionGroup[];
                placeholder?: string;
                searchValue?: string;
                searchPlaceholder?: string;
                emptyMessage?: string;
                controlClass?: HTMLAttributes['class'];
            }
    >(),
    {
        searchPlaceholder: 'Search...',
        emptyMessage: 'No results found.',
    },
);

const emit = defineEmits<{
    (event: 'select', value: AcceptableValue | AcceptableValue[]): void;
    (event: 'update:modelValue', value: AcceptableValue | AcceptableValue[] | undefined): void;
    (event: 'update:searchValue', value: string): void;
}>();

const modelValue = useVModel(props, 'modelValue', emit, {
    passive: true,
});

const searchValue = useVModel(props, 'searchValue', emit, {
    passive: true,
});

const attrs = useAttrs();

const open = ref(false);
const labelRef = useTemplateRef('labelRef');

const comboboxId = computed(() => props.id || `combobox-field-${Math.random().toString(36).substring(2, 9)}`);

const commandProps = computed(() => ({
    ...attrs,
    ...reactiveOmit(
        props,
        'label',
        'errors',
        'subLabel',
        'class',
        'controlClass',
        'id',
        'options',
        'placeholder',
        'searchPlaceholder',
        'emptyMessage',
        'disabled',
    ),
}));

const compareValues = (a: any, b: any) => {
    if (props.by === undefined) {
        if (import.meta.env.DEV) {
            console.warn(
                '[ComboboxField]: "by" prop is not defined for object comparison. Defaulting to reference equality.',
            );
        }
        return a === b;
    }

    if (props.by instanceof Function) {
        return props.by(a, b);
    }

    return a[props.by] === b[props.by];
};

const hasOptionGroups = computed(() => props.options.length > 0 && 'options' in props.options[0]);

const flattenedOptions = computed(() => {
    if (!props.options.length) {
        return [] as ComboboxOption[];
    }
    if (hasOptionGroups.value) {
        return (props.options as ComboboxOptionGroup[]).flatMap((group) => group.options);
    }
    return props.options as ComboboxOption[];
});

const isEmpty = computed(() => {
    if (Array.isArray(modelValue.value)) {
        return modelValue.value.length === 0;
    }
    return modelValue.value === undefined || modelValue.value === null || modelValue.value === '';
});

const displayValue = computed(() => {
    if (isEmpty.value) {
        return props.placeholder ?? '';
    }

    if (Array.isArray(modelValue.value)) {
        const selectedOptions = modelValue.value
            .map(
                (value) =>
                    flattenedOptions.value.find((option) =>
                        typeof value === 'object' ? compareValues(option.value, value) : option.value === value,
                    )?.label,
            )
            .filter((option): option is string => !!option);
        return selectedOptions.join(', ');
    }

    return flattenedOptions.value.find((option) =>
        typeof modelValue.value === 'object'
            ? compareValues(option.value, modelValue.value)
            : option.value === modelValue.value,
    )?.label;
});

const errorsFormatted = computed(() => {
    if (props.errors) {
        return Array.isArray(props.errors)
            ? props.errors.map((error) => ({ message: error }))
            : [{ message: props.errors }];
    }
    return undefined;
});

const showErrorAttribute = computed(() => !!errorsFormatted.value?.length || undefined);

const containerClass = cn(
    'group-data-[disabled=true]/field:opacity-50 group-data-[invalid=true]/field:group-data-[disabled=true]/field:opacity-80',
);

const triggerClass = cn(
    'w-full justify-between border-input text-left font-normal',
    'group-data-[empty=true]/field:group-data-[disabled=false]/field:text-muted-foreground',
    'group-data-[disabled=true]/field:border-0 group-data-[disabled=true]/field:bg-gray-400/50 group-data-[disabled=true]/field:text-foreground',
    'group-data-[disabled=true]/field:group-data-[empty=true]/field:text-foreground/60',
    'group-data-[invalid=true]/field:border-destructive group-data-[invalid=true]/field:bg-red-50 group-data-[invalid=true]/field:text-destructive',
    'group-data-[invalid=true]/field:group-data-[empty=true]/field:text-destructive/60',
    props.controlClass || '',
);

const handleLabelClick = () => {
    if (!props.disabled) {
        open.value = true;
    }
};

const handleInteractOutside = (event: PointerDownOutsideEvent | FocusOutsideEvent) => {
    if (event.composedPath().includes(labelRef.value?.$el)) {
        event.preventDefault();
    }
};

const isOptionSelected = (value: AcceptableValue) => {
    if (Array.isArray(modelValue.value)) {
        if (typeof value === 'object' && value !== null) {
            return modelValue.value.some((selected) => compareValues(selected, value));
        }

        return modelValue.value.includes(value);
    }
    return typeof value === 'object' && value !== null
        ? compareValues(modelValue.value, value)
        : modelValue.value === value;
};

const closeIfSingleSelect = () => {
    if (!props.multiple) {
        open.value = false;
    }
};

const handleSelection = (value: AcceptableValue | AcceptableValue[]) => {
    emit('select', value);
    closeIfSingleSelect();
};
</script>

<template>
    <Field :class="props.class" :data-invalid="showErrorAttribute" :data-disabled="disabled" :data-empty="isEmpty">
        <FieldLabel
            v-if="label"
            ref="labelRef"
            :for="comboboxId"
            :class="{
                'gap-0.5': subLabel || required,
            }"
            @click.prevent="handleLabelClick"
        >
            {{ label }}
            <span v-if="required && !hideRequiredAsterisk" class="text-destructive">*</span>
            <sub
                v-if="subLabel"
                class="text-xs text-muted-foreground group-data-[invalid=true]/field:text-destructive/80"
            >
                {{ subLabel }}
            </sub>
        </FieldLabel>
        <Popover v-model:open="open">
            <PopoverTrigger as-child>
                <Button
                    :id="comboboxId"
                    variant="outline"
                    role="combobox"
                    :aria-expanded="open"
                    :aria-invalid="showErrorAttribute"
                    :disabled="disabled"
                    :class="
                        cn(
                            'hover:group-data-[disabled=false]/field:group-data-[invalid=true]/field:bg-destructive/15 group-data-[empty=false]/field:group-data-[invalid=true]/field:hover:text-destructive',
                            containerClass,
                            triggerClass,
                        )
                    "
                >
                    <span class="truncate">{{ displayValue }}</span>
                    <ChevronsUpDownIcon class="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent class="w-[--reka-popover-trigger-width] p-0" @interact-outside="handleInteractOutside">
                <Command v-bind="commandProps" v-model="modelValue" @update:model-value="handleSelection">
                    <CommandInput v-model="searchValue" :placeholder="props.searchPlaceholder" class="h-9" />
                    <CommandEmpty>{{ props.emptyMessage }}</CommandEmpty>
                    <CommandList>
                        <slot name="options">
                            <template v-if="hasOptionGroups">
                                <CommandGroup
                                    v-for="(group, groupIndex) in props.options as ComboboxOptionGroup[]"
                                    :key="groupIndex"
                                    :heading="group.label"
                                >
                                    <CommandItem
                                        v-for="(option, index) in group.options"
                                        :key="index"
                                        :value="option.value"
                                        :disabled="option.disabled"
                                        :class="option.class"
                                    >
                                        <CheckIcon
                                            :class="
                                                cn(
                                                    'mr-2 size-4',
                                                    isOptionSelected(option.value) ? 'opacity-100' : 'opacity-0',
                                                )
                                            "
                                        />
                                        {{ option.label }}
                                    </CommandItem>
                                </CommandGroup>
                            </template>
                            <CommandGroup v-else>
                                <CommandItem
                                    v-for="(option, index) in props.options as ComboboxOption[]"
                                    :key="index"
                                    :value="option.value"
                                    :disabled="option.disabled"
                                    :class="option.class"
                                >
                                    <CheckIcon
                                        :class="
                                            cn(
                                                'mr-2 size-4',
                                                isOptionSelected(option.value) ? 'opacity-100' : 'opacity-0',
                                            )
                                        "
                                    />
                                    {{ option.label }}
                                </CommandItem>
                            </CommandGroup>
                        </slot>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
        <FieldError :errors="errorsFormatted" />
    </Field>
</template>
