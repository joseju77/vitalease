<script lang="ts" setup>
import type { CalendarRootEmits, CalendarRootProps } from 'reka-ui';
import type { DateValue } from '@internationalized/date';
import type { HTMLAttributes } from 'vue';
import { reactiveOmit } from '@vueuse/core';
import { CalendarRoot, useForwardPropsEmits } from 'reka-ui';
import { CalendarDate, DateFormatter, getLocalTimeZone, today } from '@internationalized/date';
import { computed, ref } from 'vue';
import { cn } from '@/lib/utils';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import {
    CalendarCell,
    CalendarCellTrigger,
    CalendarGrid,
    CalendarGridBody,
    CalendarGridHead,
    CalendarGridRow,
    CalendarHeadCell,
    CalendarHeader,
    CalendarNextButton,
    CalendarPrevButton,
} from '.';

const props = defineProps<CalendarRootProps & { class?: HTMLAttributes['class'] }>();

const emits = defineEmits<CalendarRootEmits>();

const delegatedProps = reactiveOmit(props, 'class', 'placeholder');

const forwarded = useForwardPropsEmits(delegatedProps, emits);

/**
 * Drives which month/year the grid displays. Month/year `NativeSelect`s let a
 * visitor jump straight to a distant year (e.g. a birth date decades back)
 * instead of clicking through `CalendarPrevButton` one month at a time.
 */
const placeholder = ref<DateValue>(
    props.placeholder ??
        (Array.isArray(props.modelValue) ? props.modelValue[0] : props.modelValue) ??
        today(getLocalTimeZone()),
);

const monthFormatter = new DateFormatter('es-MX', { month: 'long' });

const months = computed(() =>
    Array.from({ length: 12 }, (_, index) => ({
        value: index + 1,
        label: monthFormatter.format(new CalendarDate(2024, index + 1, 1).toDate(getLocalTimeZone())),
    })),
);

/** A 120-year window ending this year comfortably covers any birth date. */
const years = computed(() => {
    const currentYear = today(getLocalTimeZone()).year;
    return Array.from({ length: 121 }, (_, index) => currentYear - index);
});

/**
 * `NativeSelect`'s `update:modelValue` emit is declared as a bare value type
 * rather than a call signature, which Vue's emit-type inference reads as a
 * zero-argument event; a `v-model` computed avoids passing a manually-typed
 * handler into that mismatched slot.
 */
const selectedMonth = computed<string>({
    get: () => String(placeholder.value.month),
    set: (value) => {
        placeholder.value = (placeholder.value as CalendarDate).set({ month: Number(value) });
    },
});

const selectedYear = computed<string>({
    get: () => String(placeholder.value.year),
    set: (value) => {
        placeholder.value = (placeholder.value as CalendarDate).set({ year: Number(value) });
    },
});
</script>

<template>
    <CalendarRoot
        v-slot="{ grid, weekDays }"
        :placeholder="placeholder as any"
        :class="cn('p-3', props.class)"
        v-bind="forwarded"
        @update:placeholder="(value: DateValue) => (placeholder = value)"
    >
        <CalendarHeader>
            <CalendarPrevButton />
            <div class="flex items-center gap-1">
                <NativeSelect v-model="selectedMonth" class="h-8 pr-7 text-sm" aria-label="Mes">
                    <NativeSelectOption v-for="month in months" :key="month.value" :value="String(month.value)">
                        {{ month.label }}
                    </NativeSelectOption>
                </NativeSelect>
                <NativeSelect v-model="selectedYear" class="h-8 pr-7 text-sm" aria-label="Año">
                    <NativeSelectOption v-for="year in years" :key="year" :value="String(year)">
                        {{ year }}
                    </NativeSelectOption>
                </NativeSelect>
            </div>
            <CalendarNextButton />
        </CalendarHeader>

        <div class="mt-4 flex flex-col gap-y-4 sm:flex-row sm:gap-x-4 sm:gap-y-0">
            <CalendarGrid v-for="month in grid" :key="month.value.toString()">
                <CalendarGridHead>
                    <CalendarGridRow>
                        <CalendarHeadCell v-for="day in weekDays" :key="day">
                            {{ day }}
                        </CalendarHeadCell>
                    </CalendarGridRow>
                </CalendarGridHead>
                <CalendarGridBody>
                    <CalendarGridRow
                        v-for="(weekDates, index) in month.rows"
                        :key="`weekDate-${index}`"
                        class="mt-2 w-full"
                    >
                        <CalendarCell v-for="weekDate in weekDates" :key="weekDate.toString()" :date="weekDate">
                            <CalendarCellTrigger :day="weekDate" :month="month.value" />
                        </CalendarCell>
                    </CalendarGridRow>
                </CalendarGridBody>
            </CalendarGrid>
        </div>
    </CalendarRoot>
</template>
