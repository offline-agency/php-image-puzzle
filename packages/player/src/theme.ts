/**
 * Theme tokens shared by the player and the configurator. Hosts override the
 * --ipp-* custom properties. The dark palette follows the operating system
 * unless the host sets theme="light"; theme="dark" forces it (frameworks with
 * a class-based dark mode, such as Filament, set the attribute).
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
:host([theme='dark']) {${dark}}
@media (prefers-color-scheme: dark) {
  :host(:not([theme='light'])) {${dark}}
}
`;
