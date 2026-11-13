/** How a ticket's status and priority are shown. */
export const ticketStatus: Record<string, { badge: string; label: string }> = {
    open: { badge: 'suspended', label: 'Terbuka' },
    answered: { badge: 'active', label: 'Dijawab' },
    closed: { badge: 'cancelled', label: 'Ditutup' },
};

export const ticketPriority: Record<string, string> = {
    low: 'Rendah',
    normal: 'Normal',
    high: 'Tinggi',
};
