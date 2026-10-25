import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { StateStorage } from '@offline-agency/image-puzzle-engine';

const conformance = join(dirname(fileURLToPath(import.meta.url)), '../../../conformance/');

/** A PHP-generated definition document from the conformance fixtures. */
export function definitionDocument(name: string): Record<string, unknown> {
  const fixture = JSON.parse(readFileSync(`${conformance}mode-${name}.json`, 'utf8')) as { definition: Record<string, unknown> };
  return fixture.definition;
}

/** A PHP-generated definition with alternative piece images (conformance/media.json). */
export function mediaDocument(name: 'resolutions' | 'atlas' | 'both'): Record<string, unknown> {
  const fixture = JSON.parse(readFileSync(`${conformance}media.json`, 'utf8')) as {
    cases: { name: string; document: Record<string, unknown> }[];
  };
  const found = fixture.cases.find((c) => c.name === name);
  if (found === undefined) throw new Error(`No media case ${name}.`);
  return found.document;
}

export function manualClock(start = 1000): { now: () => number; advance: (ms: number) => void } {
  let time = start;
  return {
    now: () => time,
    advance: (ms) => {
      time += ms;
    },
  };
}

export function memoryStorage(): StateStorage & { readonly data: Map<string, string> } {
  const data = new Map<string, string>();
  return {
    data,
    getItem: (key) => data.get(key) ?? null,
    setItem: (key, value) => {
      data.set(key, value);
    },
    removeItem: (key) => {
      data.delete(key);
    },
  };
}

export interface FakeFetch {
  (input: string, init: RequestInit): Promise<Response>;
  readonly calls: { url: string; signal: AbortSignal | null | undefined }[];
}

/** Serves JSON bodies by URL suffix; `pending` URLs never resolve until aborted. */
export function fakeFetch(routes: Record<string, unknown>, pending: readonly string[] = []): FakeFetch {
  const calls: FakeFetch['calls'] = [];
  const fn = (input: string, init: RequestInit): Promise<Response> => {
    calls.push({ url: input, signal: init.signal });
    if (pending.some((suffix) => input.endsWith(suffix))) {
      return new Promise((_resolve, reject) => {
        init.signal?.addEventListener('abort', () => {
          reject(new DOMException('aborted', 'AbortError'));
        });
      });
    }
    const key = Object.keys(routes).find((suffix) => input.endsWith(suffix));
    if (key === undefined) return Promise.resolve(new Response('missing', { status: 404 }));
    const body = routes[key];
    return Promise.resolve(new Response(typeof body === 'string' ? body : JSON.stringify(body), { status: 200 }));
  };
  return Object.assign(fn, { calls });
}

/** Lets queued microtasks and resolved fetch promises settle. */
export async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await new Promise((resolve) => setTimeout(resolve, 0));
  }
}

export function key(target: Element, keyName: string, init: KeyboardEventInit = {}): void {
  target.dispatchEvent(new KeyboardEvent('keydown', { key: keyName, bubbles: true, composed: true, cancelable: true, ...init }));
}
