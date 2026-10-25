/**
 * Theme tokens shared by the player and the configurator. Hosts override the
 * --ipp-* custom properties. The dark palette follows the operating system
 * unless the host sets theme="light"; theme="dark" forces it. Frameworks with
 * a class-based dark mode (Filament, Tailwind) use theme-class instead.
 */
const dark = `
  --ipp-fg: #e6e9ee;
  --ipp-muted: #a9b2be;
  --ipp-bg: #15191f;
  --ipp-surface: #232933;
  --ipp-border: #56606d;
  --ipp-accent: #7aa7ff;
  --ipp-accent-fg: #0b1220;
  --ipp-focus: #ff7ab8;
  --ipp-correct: #5fd38a;
  --ipp-hint: #ffb45c;
  --ipp-error: #ff8a80;
  --ipp-tray: #1d232b;
  color-scheme: dark;
`;

export const themeTokens: string = `
:host {
  --ipp-font: system-ui, sans-serif;
  --ipp-fg: #1d232b;
  --ipp-muted: #55606e;
  --ipp-bg: #ffffff;
  --ipp-surface: #eef1f5;
  --ipp-border: #c4ccd6;
  --ipp-accent: #1f5fbf;
  --ipp-accent-fg: #ffffff;
  --ipp-focus: #d1006a;
  --ipp-correct: #1b7f3b;
  --ipp-hint: #b35c00;
  --ipp-error: #b00020;
  --ipp-radius: 6px;
  --ipp-gap: 2px;
  --ipp-tray: #dde3ea;
  --ipp-duration: 160ms;
  color-scheme: light;
}
:host([theme='dark']),
:host([data-theme='dark']) {${dark}}
@media (prefers-color-scheme: dark) {
  :host(:not([theme='light']):not([data-theme='light'])) {${dark}}
}
`;

/**
 * Follows a class on <html> (theme-class="dark" for Filament and Tailwind's
 * class strategy): sets data-theme on the host to "dark" or "light" and keeps
 * it in sync. Returns the function that stops observing.
 */
export function followThemeClass(host: HTMLElement, className: string): () => void {
  const root = host.ownerDocument.documentElement;
  const apply = (): void => {
    host.setAttribute('data-theme', root.classList.contains(className) ? 'dark' : 'light');
  };
  apply();
  const view = host.ownerDocument.defaultView;
  if (view === null || typeof view.MutationObserver === 'undefined') {
    return () => {
      host.removeAttribute('data-theme');
    };
  }
  const observer = new view.MutationObserver(apply);
  observer.observe(root, { attributes: true, attributeFilter: ['class'] });
  return () => {
    observer.disconnect();
    host.removeAttribute('data-theme');
  };
}
