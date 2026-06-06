/**
 * useTimezoneList — Intl-timezone partitioning for the schedule wizard.
 *
 * Splits the IANA timezone list into a "common" set (shown first in the
 * dropdown) and the rest (alphabetised). The split is the same on every
 * platform — the common set is declared by the wizard, not the OS.
 */

export interface CommonTimezoneSet {
  common: string[]
  rest: string[]
}

/** Split the Intl timezone list into a "common" and a "rest" set, both sorted. */
export function partitionTimezones(
  allTimezones: string[],
  commonZoneValues: Set<string>,
): CommonTimezoneSet {
  const common = allTimezones
    .filter((tz) => commonZoneValues.has(tz))
    .sort((a, b) => a.localeCompare(b))
  const rest = allTimezones
    .filter((tz) => !commonZoneValues.has(tz))
    .sort((a, b) => a.localeCompare(b))
  return { common, rest }
}

/** Default value to seed the timezone field with. */
export function defaultTimezone(): string {
  return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
}

/** Build the default set of IANA timezones (with a small common-first sort). */
export function buildTimezoneList(
  intlTimezones: string[],
  commonZoneValues: Set<string>,
): { value: string; label: string }[] {
  const { common, rest } = partitionTimezones(intlTimezones, commonZoneValues)
  return [
    ...common.map((tz) => ({ value: tz, label: tz })),
    ...rest.map((tz) => ({ value: tz, label: tz })),
  ]
}
