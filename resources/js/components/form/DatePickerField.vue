<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { DateFormatter, DateValue, getLocalTimeZone, parseDate } from '@internationalized/date';
import { reactiveOmit } from '@vueuse/core';
import { CalendarIcon } from '@lucide/vue';
import type { CalendarRootProps, FocusOutsideEvent, PointerDownOutsideEvent } from 'reka-ui';
import { computed, HTMLAttributes, ref, useTemplateRef, watch } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type BaseField = {
    id?: string;
    modelValue?: string | string[];
    label?: string;
    errors?: string[] | string | undefined;
    subLabel?: string;
    class?: HTMLAttributes['class'];
    required?: boolean;
    hideRequiredAsterisk?: boolean;
};

const props = defineProps<
    Omit<CalendarRootProps, 'placeholder' | 'modelValue'> &
        BaseField & {
            placeholder?: string;
            controlClass?: HTMLAttributes['class'];
            dateFormatter?: (date: DateValue) => string;
        }
>();

const model = defineModel<string | string[] | undefined>();

const labelRef = useTemplateRef('labelRef');

const open = ref(false);

const datePickerId = computed(() => props.id || `date-picker-${Math.random().toString(36).substring(2, 9)}`);

const calendarProps = reactiveOmit(
    props,
    'label',
    'modelValue',
    'errors',
    'subLabel',
    'class',
    'placeholder',
    'id',
    'disabled',
    'controlClass',
    'dateFormatter',
    'multiple',
);

const modelFormatted = computed(() => {
    if (!props.modelValue) {
        return undefined;
    }

    if (Array.isArray(props.modelValue)) {
        return props.modelValue.map((dateString) => parseDate(dateString));
    } else {
        return parseDate(props.modelValue);
    }
});

const datePicked = ref<DateValue | DateValue[] | undefined>(modelFormatted.value);

const errorsFormatted = computed(() => {
    if (props.errors) {
        return Array.isArray(props.errors)
            ? props.errors.map((error) => ({ message: error }))
            : [{ message: props.errors }];
    }
    return undefined;
});

const showErrorAttribute = computed(() => !!errorsFormatted.value?.length || undefined);

const displayValue = computed(() => {
    if (!datePicked.value) {
        return props.placeholder;
    }

    const formatter = (value: DateValue): string =>
        props.dateFormatter
            ? props.dateFormatter(value)
            : new DateFormatter('es-MX', {
                  day: '2-digit',
                  month: 'short',
                  year: 'numeric',
              })
                  .format(value.toDate(getLocalTimeZone()))
                  .replace(/\s/g, '-');

    if (Array.isArray(datePicked.value)) {
        return datePicked.value.map((date) => formatter(date as DateValue)).join(' ~ ');
    }

    return formatter(datePicked.value as DateValue);
});

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

const closeOnSingleSelect = () => {
    if (!props.multiple) {
        open.value = false;
    }
};

const datePickedFormatted = computed(() => {
    if (!datePicked.value) {
        return;
    }

    if (Array.isArray(datePicked.value)) {
        return datePicked.value.map((date) => date.toString());
    } else {
        return datePicked.value.toString();
    }
});

watch(
    () => props.modelValue,
    (newValue) => {
        if (newValue?.toString() !== datePickedFormatted.value?.toString()) {
            datePicked.value = modelFormatted.value;
        }
    },
);

watch(datePicked, () => {
    model.value = datePickedFormatted.value;
});
</script>

<template>
    <Field :class="props.class" :data-invalid="showErrorAttribute" :data-disabled="props.disabled">
        <FieldLabel
            v-if="label"
            ref="labelRef"
            :for="datePickerId"
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
                    :id="datePickerId"
                    variant="outline"
                    :aria-invalid="showErrorAttribute"
                    :data-empty="Array.isArray(props.modelValue) ? props.modelValue.length === 0 : !props.modelValue"
                    :disabled
                    :class="
                        cn(
                            'justify-start border-input text-left font-normal shadow-none data-[empty=true]:text-muted-foreground',
                            'disabled:cursor-not-allowed disabled:border-0 disabled:bg-gray-400/50 disabled:text-foreground disabled:opacity-50 disabled:data-[empty=true]:text-foreground/60',
                            'aria-invalid:border-destructive aria-invalid:bg-red-50 aria-invalid:text-destructive aria-invalid:ring-destructive/20 aria-invalid:data-[empty=true]:text-destructive/60',
                            'disabled:aria-invalid:opacity-80',
                            'hover:group-data-[disabled=false]/field:group-data-[invalid=true]/field:bg-destructive/15 group-data-[empty=false]/field:group-data-[invalid=true]/field:hover:text-destructive',
                            'overflow-hidden',
                            props.controlClass || '',
                        )
                    "
                >
                    <CalendarIcon class="size-4 shrink-0" />
                    <span class="block truncate">{{ displayValue }}</span>
                </Button>
            </PopoverTrigger>
            <PopoverContent class="w-auto p-0" @interact-outside="handleInteractOutside">
                <Calendar
                    v-bind="calendarProps"
                    v-model="datePicked as DateValue | DateValue[] | undefined"
                    :multiple="props.multiple || Array.isArray(props.modelValue)"
                    @update:model-value="closeOnSingleSelect"
                />
            </PopoverContent>
        </Popover>
        <FieldError :errors="errorsFormatted" />
    </Field>
</template>
