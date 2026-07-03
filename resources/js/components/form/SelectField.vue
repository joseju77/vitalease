<script setup lang="ts">
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import {
    NativeSelect,
    NativeSelectOptGroup,
    NativeSelectOption,
    type NativeSelectProps,
} from '@/components/ui/native-select';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { reactiveOmit } from '@vueuse/core';
import type {
    SelectContentProps,
    SelectGroupProps,
    SelectItemProps,
    SelectRootProps,
    SelectTriggerProps,
    SelectValueProps,
} from 'reka-ui';
import { computed, HTMLAttributes, ref } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type SelectOption = SelectItemProps & { class?: HTMLAttributes['class']; label: string };

type SelectOptionGroup = {
    label: string;
    options: SelectOption[];
};

type BaseField = {
    id?: string;
    label?: string;
    errors?: string[] | string | undefined;
    subLabel?: string;
    class?: HTMLAttributes['class'];
    nativeSelect?: boolean;
    required?: boolean;
    hideRequiredAsterisk?: boolean;
};

const props = withDefaults(
    defineProps<
        BaseField &
            SelectRootProps & {
                triggerProps?: SelectTriggerProps;
                contentProps?: SelectContentProps & { class?: HTMLAttributes['class'] };
                valueProps?: SelectValueProps & { class?: HTMLAttributes['class'] };
                groupProps?: SelectGroupProps & { class?: HTMLAttributes['class'] };
            } & NativeSelectProps & {
                options: SelectOption[] | SelectOptionGroup[];
                controlClass?: HTMLAttributes['class'];
            }
    >(),
    {
        nativeSelect: false,
    },
);

const open = ref(false);

const selectProps = reactiveOmit(
    props,
    'label',
    'errors',
    'subLabel',
    'class',
    'controlClass',
    'id',
    'options',
    'nativeSelect',
    'triggerProps',
    'contentProps',
    'valueProps',
    'groupProps',
);

const selectId = computed(() => props.id || `select-field-${Math.random().toString(36).substring(2, 9)}`);

const errorsFormatted = computed(() => {
    if (props.errors) {
        return Array.isArray(props.errors)
            ? props.errors.map((error) => ({ message: error }))
            : [{ message: props.errors }];
    }
    return undefined;
});

const showErrorAttribute = computed(() => !!errorsFormatted.value?.length || undefined);

const isEmpty = computed(() => {
    if (Array.isArray(props.modelValue)) {
        return props.modelValue.length === 0;
    }

    return props.modelValue === undefined || props.modelValue === null || props.modelValue === '';
});

const containerClass = cn(
    'group-data-[disabled=true]/field:opacity-50 group-data-[invalid=true]/field:group-data-[disabled=true]/field:opacity-80',
);

const triggerClass = cn(
    'group-data-[empty=true]/field:group-data-[disabled=false]/field:text-muted-foreground group-data-[empty=true]/field:group-data-[disabled=false]/field:[&>span]:text-muted-foreground',
    'group-data-[disabled=true]/field:border-0 group-data-[disabled=true]/field:bg-gray-400/50 group-data-[disabled=true]/field:text-foreground',
    'group-data-[disabled=true]/field:group-data-[empty=true]/field:text-foreground/60 group-data-[disabled=true]/field:[&>span]:text-foreground group-data-[disabled=true]/field:group-data-[empty=true]/field:[&>span]:text-foreground/60',
    'group-data-[invalid=true]/field:border-destructive group-data-[invalid=true]/field:bg-red-50 group-data-[invalid=true]/field:text-destructive',
    'group-data-[invalid=true]/field:group-data-[empty=true]/field:text-destructive/60 group-data-[invalid=true]/field:[&>span]:text-destructive group-data-[invalid=true]/field:group-data-[empty=true]/field:[&>span]:text-destructive/60',
    props.controlClass,
);

const iconClass = cn(
    'stroke-foreground group-data-[empty=true]/field:group-data-[disabled=false]/field:stroke-muted-foreground',
    'group-data-[disabled=true]/field:stroke-foreground group-data-[disabled=true]/field:group-data-[empty=true]/field:stroke-foreground/60',
    'group-data-[invalid=true]/field:stroke-destructive group-data-[invalid=true]/field:group-data-[empty=true]/field:stroke-destructive/60',
);

const handleLabelClick = () => {
    if (!props.disabled && !props.nativeSelect) {
        open.value = true;
    }
};
</script>

<template>
    <Field
        :class="props.class"
        :data-disabled="props.disabled"
        :data-empty="isEmpty"
        :data-invalid="showErrorAttribute"
    >
        <FieldLabel
            v-if="label"
            :class="{
                'gap-0.5': subLabel || required,
            }"
            :for="selectId"
            @click="handleLabelClick"
        >
            {{ label }}
            <span v-if="required && !hideRequiredAsterisk" class="text-destructive"> * </span>
            <sub v-if="subLabel" class="text-xs text-muted-foreground group-data-[invalid=true]/field:text-destructive">
                {{ subLabel }}
            </sub>
        </FieldLabel>
        <NativeSelect
            v-if="nativeSelect"
            v-bind="{ ...$attrs, ...selectProps }"
            :id="selectId"
            :model-value
            :container-class="containerClass"
            :class="triggerClass"
            :icon-class="iconClass"
        >
            <NativeSelectOption v-if="props.valueProps?.placeholder" disabled value="" class="text-gray-400">
                {{ props.valueProps.placeholder }}
            </NativeSelectOption>
            <template v-if="options.length && 'options' in options[0]">
                <NativeSelectOptGroup
                    v-for="(group, groupIndex) in options as SelectOptionGroup[]"
                    :key="groupIndex"
                    :label="group.label"
                >
                    <NativeSelectOption
                        v-for="(option, index) in group.options"
                        :key="index"
                        :value="option.value"
                        :disabled="option.disabled"
                        :class="option.class"
                    >
                        {{ option.label }}
                    </NativeSelectOption>
                </NativeSelectOptGroup>
            </template>
            <template v-else>
                <NativeSelectOption
                    v-for="(option, index) in options as SelectOption[]"
                    :key="index"
                    :value="option.value"
                    :disabled="option.disabled"
                    :class="option.class"
                >
                    {{ option.label }}
                </NativeSelectOption>
            </template>
        </NativeSelect>
        <Select v-else v-bind="{ ...$attrs, ...selectProps }" v-model:open="open" :model-value>
            <SelectTrigger
                v-bind="props.triggerProps"
                :id="selectId"
                :class="cn(containerClass, triggerClass)"
                :disabled
            >
                <SelectValue v-bind="props.valueProps" />
            </SelectTrigger>
            <SelectContent v-bind="props.contentProps">
                <slot name="options">
                    <template v-if="options.length && 'options' in options[0]">
                        <SelectGroup
                            v-for="(group, groupIndex) in options as SelectOptionGroup[]"
                            :key="groupIndex"
                            v-bind="props.groupProps"
                        >
                            <SelectLabel>{{ group.label }}</SelectLabel>
                            <SelectItem
                                v-for="(option, index) in group.options"
                                :key="index"
                                :class="option.class"
                                :value="option.value"
                                :disabled="option.disabled"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectGroup>
                    </template>

                    <SelectGroup v-else v-bind="props.groupProps">
                        <SelectItem
                            v-for="(option, index) in options as SelectOption[]"
                            :key="index"
                            :class="option.class"
                            :value="option.value"
                            :disabled="option.disabled"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectGroup>
                </slot>
            </SelectContent>
        </Select>
        <FieldError :errors="errorsFormatted" />
    </Field>
</template>
