/**
 * Shared helper to build a display name from atomized client/registration name parts.
 * Used by Admin Clients, Triage Index, TriageClientBinder, and any other UI that shows client or registration names.
 */
export type NameParts = {
	first_name?: string | null;
	middle_name?: string | null;
	last_name?: string | null;
};

/**
 * Returns a single display string from first_name, middle_name, last_name.
 * Falls back to "—" when all parts are empty.
 */
export function clientDisplayName(parts: NameParts): string {
	return [parts.first_name, parts.middle_name, parts.last_name].filter(Boolean).join(' ').trim() || '—';
}
