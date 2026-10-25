import { PuzzleError } from './errors.js';
import type { PuzzleEngine } from './engine.js';

/** The Storage subset used for saved games (window.localStorage fits). */
export interface StateStorage {
  getItem(key: string): string | null;
  setItem(key: string, value: string): void;
  removeItem(key: string): void;
}

export type PersistResult = { readonly ok: true } | { readonly ok: false; readonly error: PuzzleError };
export type RestoreResult = { readonly restored: true } | { readonly restored: false; readonly error?: PuzzleError };

export const DEFAULT_PREFIX = 'image-puzzle';

/**
 * window.localStorage when it exists and is usable, otherwise null. Access
 * itself can throw (privacy modes, sandboxed frames), so it is guarded.
 */
export function browserStorage(): StateStorage | null {
  try {
    const storage = (globalThis as { localStorage?: StateStorage }).localStorage;
    if (storage === undefined) return null;
    const probe = `${DEFAULT_PREFIX}:probe`;
    storage.setItem(probe, '1');
    storage.removeItem(probe);
    return storage;
  } catch {
    return null;
  }
}

export function storageKey(definitionId: string, prefix = DEFAULT_PREFIX): string {
  return `${prefix}:${definitionId}`;
}

/** Saves the current state; never throws (quota, unavailable storage). */
export function saveState(engine: PuzzleEngine, storage: StateStorage, prefix = DEFAULT_PREFIX): PersistResult {
  try {
    const state = engine.exportState();
    storage.setItem(storageKey(state.definitionId, prefix), JSON.stringify(state));
    return { ok: true };
  } catch (error) {
    return { ok: false, error: asStorageError(error) };
  }
}

/**
 * Restores the saved state for the loaded definition. A missing entry is not
 * an error. A corrupt, stale or conflicting entry leaves both the current
 * game and the stored data untouched and is reported.
 */
export function restoreState(engine: PuzzleEngine, storage: StateStorage, prefix = DEFAULT_PREFIX): RestoreResult {
  const definition = engine.definition;
  if (definition === null) {
    return { restored: false, error: new PuzzleError('invalid', 'No puzzle is loaded.') };
  }
  let raw: string | null;
  try {
    raw = storage.getItem(storageKey(definition.id, prefix));
  } catch (error) {
    return { restored: false, error: asStorageError(error) };
  }
  if (raw === null) {
    return { restored: false };
  }
  let data: unknown;
  try {
    data = JSON.parse(raw);
  } catch {
    return { restored: false, error: new PuzzleError('storage', 'The saved game is not valid JSON.') };
  }
  try {
    engine.importState(data);
    return { restored: true };
  } catch (error) {
    return { restored: false, error: error instanceof PuzzleError ? error : asStorageError(error) };
  }
}

export function clearState(definitionId: string, storage: StateStorage, prefix = DEFAULT_PREFIX): PersistResult {
  try {
    storage.removeItem(storageKey(definitionId, prefix));
    return { ok: true };
  } catch (error) {
    return { ok: false, error: asStorageError(error) };
  }
}

export interface AutoPersistOptions {
  readonly prefix?: string;
  /** Delay before writing after the last change (ms). Default 250. */
  readonly debounceMs?: number;
  readonly onError?: (error: PuzzleError) => void;
}

/**
 * Saves after state changes, debounced, and flushes on stop. Returns a stop
 * function that unsubscribes and cancels the pending timer.
 */
export function autoPersist(engine: PuzzleEngine, storage: StateStorage, options: AutoPersistOptions = {}): () => void {
  const delay = options.debounceMs ?? 250;
  let timer: ReturnType<typeof setTimeout> | null = null;
  let stopped = false;

  const flush = (): void => {
    timer = null;
    if (engine.status === 'destroyed' || engine.definition === null) return;
    const result = saveState(engine, storage, options.prefix);
    if (!result.ok) options.onError?.(result.error);
  };
  const unsubscribe = engine.subscribe('state-change', () => {
    if (stopped) return;
    if (timer !== null) clearTimeout(timer);
    timer = setTimeout(flush, delay);
  });

  return () => {
    if (stopped) return;
    stopped = true;
    unsubscribe();
    if (timer !== null) {
      clearTimeout(timer);
      flush();
    }
  };
}

function asStorageError(error: unknown): PuzzleError {
  return new PuzzleError('storage', error instanceof Error ? error.message : String(error), { cause: error });
}
