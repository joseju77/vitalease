<script setup lang="ts">
import { computed } from 'vue';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps<{
    id: string;
    label: string;
    modelValue?: string;
    errors?: string;
    required?: boolean;
}>();
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();
const formattedErrors = computed(() => (props.errors ? [{ message: props.errors }] : undefined));
</script>

<template>
    <Field :data-invalid="!!errors || undefined">
        <FieldLabel :for="id">{{ label }}<span v-if="required" class="text-destructive">*</span></FieldLabel>
        <Textarea
            :id="id"
            :model-value="modelValue"
            :aria-invalid="!!errors || undefined"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <FieldError :errors="formattedErrors" />
    </Field>
</template>
