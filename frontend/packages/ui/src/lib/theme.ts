/**
 * Follows the OS light/dark preference by toggling the `dark` class on
 * <html>, which the shadcn theme in globals.css keys off.
 */
export function syncSystemTheme(): () => void {
  const media = window.matchMedia("(prefers-color-scheme: dark)")
  const apply = () => document.documentElement.classList.toggle("dark", media.matches)
  apply()
  media.addEventListener("change", apply)
  return () => media.removeEventListener("change", apply)
}
