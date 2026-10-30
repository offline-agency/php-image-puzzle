/*
 * The engine targets any ES2022 runtime that also provides these web-platform
 * globals (all evergreen browsers and Node.js >= 18). The build deliberately
 * omits the DOM library so the headless core cannot depend on documents or
 * elements; only the members actually used are declared here.
 */

declare class TextEncoder {
  encode(input?: string): Uint8Array;
}

declare function structuredClone<T>(value: T): T;
declare function queueMicrotask(callback: () => void): void;
declare function setTimeout(callback: () => void, ms?: number): number;
declare function clearTimeout(handle: number | undefined): void;

declare const performance: { now(): number } | undefined;
declare const crypto: { getRandomValues<T extends Uint32Array>(array: T): T } | undefined;
