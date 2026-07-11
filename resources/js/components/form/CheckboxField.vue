<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { cn } from '@/lib/utils';
import { reactiveOmit } from '@vueuse/core';
import type { CheckboxRootProps } from 'reka-ui';
import { computed, HTMLAttributes, useAttrs } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type BaseField = {
    label?: string;
    errors?: string[] | string | undefined;
    description?: string;
    class?: HTMLAttributes['class'];
    asCard?: boolean;
    required?: boolean;
    hideRequiredAsterisk?: boolean;
};

const props = defineProps<
    BaseField &
        CheckboxRootProps & {
            controlClass?: HTMLAttributes['class'];
        }
>();

const attrs = useAttrs();

const checkboxId = computed(() => props.id || `checkbox-field-${Math.random().toString(36).substring(2, 9)}`);

const checkboxProps = computed(() => ({
    ...attrs,
    ...reactiveOmit(props, 'label', 'errors', 'description', 'class', 'controlClass', 'id'),
    id: checkboxId.value,
    'aria-invalid': showErrorAttribute.value,
    class: cn(
        'mt-px aria-invalid:border-destructive disabled:data-[state=checked]:bg-foreground aria-invalid:data-[state=checked]:bg-destructive',
        'disabled:aria-invalid:opacity-80',
        props.controlClass,
    ),
}));

const errorsFormatted = computed(() => {
    if (props.errors) {
        return Array.isArray(props.errors)
            ? props.errors.map((error) => ({ message: error }))
            : [{ message: props.errors }];
    }
    return undefined;
});

const showErrorAttribute = computed(() => !!errorsFormatted.value?.length || undefined);

const descriptionClass = cn(
    'text-sm text-muted-foreground group-data-[disabled=true]/field:opacity-50 group-data-[invalid=true]/field:text-destructive/80',
);
</script>

<template>
    <Field :class="props.class" :data-invalid="showErrorAttribute" :data-disabled="props.disabled">
        <div v-if="!asCard" class="flex items-start gap-2">
            <Checkbox v-bind="checkboxProps" />
            <div class="grid gap-2">
                <FieldLabel v-if="label" :for="checkboxId" class="flex-col items-start gap-0.5">
                    <span class="flex items-center gap-1">
                        {{ label }}
                        <span v-if="required && !hideRequiredAsterisk" class="text-destructive"> * </span>
                    </span>
                </FieldLabel>
                <p v-if="description" :class="descriptionClass">
                    {{ description }}
                </p>
            </div>
        </div>
        <FieldLabel
            v-else
            :class="
                cn(
                    'flex items-start gap-3 rounded-lg border border-input! p-3 hover:group-data-[disabled=false]/field:cursor-pointer hover:group-data-[disabled=false]/field:bg-accent/50',
                    'group-data-[invalid=true]/field:border-destructive! group-data-[invalid=true]/field:bg-red-50! group-data-[invalid=true]/field:group-data-[disabled=false]/field:hover:bg-destructive/10! group-data-[invalid=true]/field:has-data-[state=checked]:bg-destructive/15!',
                    '[&>button]:opacity-100!',
                )
            "
        >
            <Checkbox v-bind="checkboxProps" />
            <div class="grid gap-1.5 font-normal">
                <span class="flex items-center gap-1">
                    {{ label }}
                    <span v-if="required && !hideRequiredAsterisk" class="text-destructive"> * </span>
                </span>
                <p v-if="description" :class="descriptionClass">
                    {{ description }}
                </p>
            </div>
        </FieldLabel>
        <FieldError :errors="errorsFormatted" />
    </Field>
</template>
