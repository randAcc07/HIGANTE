import type { DecisionStatus, ExcuseReason, ExcuseStatus } from '@/types';

export const reasonLabels: Record<ExcuseReason, string> = {
    medical: 'Medical certificate',
    illness: 'Illness (no certificate)',
    school_activity: 'School activity',
    other: 'Other',
};

export const statusLabels: Record<ExcuseStatus, string> = {
    pending: 'Pending',
    approved: 'Approved',
    partial: 'Partly approved',
    rejected: 'Not approved',
    revoked: 'Revoked',
};

export const decisionLabels: Record<DecisionStatus, string> = {
    pending: 'Waiting for the teacher',
    approved: 'Approved',
    declined: 'Not approved',
    revoked: 'Revoked',
};

/** "Late · 3 days", for an excuse submitted past its deadline. */
export function lateLabel(daysLate: number): string {
    return `Late · ${daysLate} day${daysLate === 1 ? '' : 's'}`;
}

/** "1.2 MB", "340 KB". */
export function fileSize(bytes: number): string {
    return bytes >= 1_000_000
        ? `${(bytes / 1_000_000).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1000))} KB`;
}
