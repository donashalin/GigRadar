const DEFAULT_FORMAT: Intl.DateTimeFormatOptions = { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' };

/**
 * Format a concert date. Prefers the venue-local date (YYYY-MM-DD) so a show never
 * appears on a different day because of the viewer's timezone.
 */
export function formatConcertDate(localDate: string | null, startsAt: string, options: Intl.DateTimeFormatOptions = DEFAULT_FORMAT): string {
    if (localDate) {
        return new Date(`${localDate}T00:00:00Z`).toLocaleDateString('en-GB', { ...options, timeZone: 'UTC' });
    }

    return new Date(startsAt).toLocaleDateString('en-GB', options);
}
