/**
 * One measure per day as thin bars, newest on the right: one series, so no
 * legend; the title names it. Hovering (or focusing) a day shows its date
 * and value; a table carries the same numbers for screen readers. A day
 * without a value (`null`) has no bar.
 */
export function DailyBars({
    title,
    points,
    format,
}: {
    title: string;
    points: { date: string; value: number | null }[];
    format: (value: number) => string;
}) {
    const max = Math.max(0, ...points.map((point) => point.value ?? 0));
    const first = points[0]?.date;
    const last = points[points.length - 1]?.date;

    return (
        <figure className="grid gap-2">
            <figcaption className="flex items-baseline justify-between gap-3 text-sm">
                <span className="font-medium">{title}</span>
                <span className="text-xs text-muted-foreground">
                    Tertinggi {format(max)}
                </span>
            </figcaption>

            <div
                className="flex h-28 items-end gap-0.5 border-b border-border"
                aria-hidden="true"
            >
                {points.map((point) => {
                    const share =
                        point.value === null || max === 0
                            ? 0
                            : (point.value / max) * 100;

                    return (
                        <div
                            key={point.date}
                            className="group relative flex h-full flex-1 items-end"
                        >
                            {point.value !== null && point.value > 0 && (
                                <div
                                    className="w-full rounded-t-[4px] bg-chart-1 transition-opacity group-hover:opacity-80"
                                    style={{
                                        height: `${Math.max(share, 2)}%`,
                                    }}
                                />
                            )}
                            <div className="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded-lg border bg-popover px-2 py-1 text-xs whitespace-nowrap text-popover-foreground shadow-md group-hover:block">
                                <span className="text-muted-foreground">
                                    {shortDate(point.date)}
                                </span>{' '}
                                <span className="font-semibold">
                                    {point.value === null
                                        ? 'belum diukur'
                                        : format(point.value)}
                                </span>
                            </div>
                        </div>
                    );
                })}
            </div>

            {first && last && (
                <div className="flex justify-between text-xs text-muted-foreground">
                    <span>{shortDate(first)}</span>
                    <span>{shortDate(last)}</span>
                </div>
            )}

            <table className="sr-only">
                <caption>{title}</caption>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Nilai</th>
                    </tr>
                </thead>
                <tbody>
                    {points.map((point) => (
                        <tr key={point.date}>
                            <td>{shortDate(point.date)}</td>
                            <td>
                                {point.value === null
                                    ? 'belum diukur'
                                    : format(point.value)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </figure>
    );
}

/** "12 Nov" for a YYYY-MM-DD day. */
function shortDate(date: string): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
    });
}
