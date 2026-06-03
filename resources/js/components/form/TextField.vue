<script setup lang="ts">
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { reactiveOmit } from '@vueuse/core';
import { computed, type InputHTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type BaseField = {
    id?: string;
    modelValue?: string;
    label?: string;
    errors?: string[] | string | undefined;
    subLabel?: string;
    class?: InputHTMLAttributes['class'];
    disabled?: boolean;
    required?: boolean;
    hideRequiredAsterisk?: boolean;
};

const props = withDefaults(
    defineProps<
        BaseField & {
            controlClass?: InputHTMLAttributes['class'];
            type?: InputHTMLAttributes['type'];
        }
    >(),
    {
        type: 'text',
    },
);

const inputId = computed(() => props.id || `input-field-${Math.random().toString(36).substring(2, 9)}`);

const inputProps = reactiveOmit(props, 'label', 'errors', 'subLabel', 'class', 'controlClass', 'id');

const errorsFormatted = computed(() => {
    if (props.errors) {
        return Array.isArray(props.errors)
            ? props.errors.map((error) => ({ message: error }))
            : [{ message: props.errors }];
    }
    return undefined;
});

const showErrorAttribute = computed(() => !!errorsFormatted.value?.length || undefined);
</script>

<template>
    <Field :class="props.class" :data-invalid="showErrorAttribute" :data-disabled="disabled">
        <FieldLabel
            v-if="label"
            :for="inputId"
            :class="{
                'gap-0.5': subLabel || required,
            }"
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
        <Input
            v-bind="{ ...inputProps, ...$attrs }"
            :id="inputId"
            :model-value="modelValue"
            :class="controlClass"
            :aria-invalid="showErrorAttribute"
        />
        <FieldError :errors="errorsFormatted" />
    </Field>
</template>
