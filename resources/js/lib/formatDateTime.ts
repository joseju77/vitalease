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
