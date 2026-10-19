/** "08:30" → minutes since midnight. */
export function toMinutes(time: string): number {
    const [hours, minutes] = time.split(':').map(Number);

    return hours * 60 + minutes;
}

/** Minutes since midnight → "08:30". */
export function fromMinutes(minutes: number): string {
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}

/** "13:30" → "1:30 PM". */
export function formatTime(time: string): string {
    const minutes = toMinutes(time);
    const hours = Math.floor(minutes / 60);

    return `${hours % 12 || 12}:${String(minutes % 60).padStart(2, '0')} ${hours < 12 ? 'AM' : 'PM'}`;
}

export const WEEKDAYS: Record<number, string> = {
    1: 'Monday',
    2: 'Tuesday',
    3: 'Wednesday',
    4: 'Thursday',
    5: 'Friday',
    6: 'Saturday',
    7: 'Sunday',
};
