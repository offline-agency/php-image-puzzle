/**
 * Text-safe DOM construction: content is always set with textContent and
 * attributes with setAttribute, never through HTML parsing.
 */
export function el<K extends keyof HTMLElementTagNameMap>(
  doc: Document,
  tag: K,
  attributes: Readonly<Record<string, string>> = {},
  text?: string,
): HTMLElementTagNameMap[K] {
  const element = doc.createElement(tag);
  for (const [name, value] of Object.entries(attributes)) {
    element.setAttribute(name, value);
  }
  if (text !== undefined) {
    element.textContent = text;
  }
  return element;
}

/** Percentage string with bounded precision. */
export function pct(value: number): string {
  return `${Math.round(value * 1e4) / 1e4}%`;
}

/** Resolves a definition-relative asset path against the definition URL; only http(s), blob and file URLs are allowed. */
export function resolveAsset(path: string, base: string): string {
  const url = new URL(path, base);
  if (!['http:', 'https:', 'blob:', 'file:'].includes(url.protocol)) {
    throw new Error(`Unsupported asset URL scheme ${url.protocol}`);
  }
  return url.href;
}

/** A CSS url() value for an already-resolved, percent-encoded URL. */
export function cssUrl(href: string): string {
  return `url("${href.replace(/["\\\n\r]/g, (char) => encodeURIComponent(char))}")`;
}
