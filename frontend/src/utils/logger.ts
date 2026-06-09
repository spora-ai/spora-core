/* eslint-disable no-console */
// The single point in the app that's allowed to import `console`. All other
// code uses this module so we keep one consistent prefix and one place to
// flip behavior (e.g. ship to a remote sink later).
const tag = '[spora]'

export const log = {
  error: (...a: unknown[]): void => console.error(tag, ...a),
  warn:  (...a: unknown[]): void => console.warn(tag, ...a),
  debug: (...a: unknown[]): void => { if (import.meta.env.DEV) console.debug(tag, ...a) },
}
