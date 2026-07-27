const dateTimeFormatter = new Intl.DateTimeFormat('es-MX', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

/**
 * Formats an ISO 8601 timestamp (as serialized by Laravel's
 * `immutable_datetime` cast) into a readable, localized date/time string.
 */
export function formatDateTime(value: string): string {
    return dateTimeFormatter.format(new Date(value));
}

const dateFormatter = new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium' });

/**
 * Formats a calendar date (`Y-m-d`, as serialized for `date` columns) into a
 * readable, localized date string. The parts are read as a local date so the
 * browser time zone never shifts the day.
 */
export function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return dateFormatter.format(new Date(year, month - 1, day));
}
