import { afterEach, describe, expect, it, vi } from 'vitest';
import { autoPersist, browserStorage, createEngine, restoreState, saveState, storageKey } from '../src/index.js';
import type { StateStorage } from '../src/index.js';
import { definitionDocument } from './fixtures.js';

class MemoryStorage implements StateStorage {
  readonly items = new Map<string, string>();
  failWrites = false;
  getItem(key: string): string | null {
    return this.items.get(key) ?? null;
  }
  setItem(key: string, value: string): void {
    if (this.failWrites) throw new Error('QuotaExceededError');
    this.items.set(key, value);
  }
  removeItem(key: string): void {
    this.items.delete(key);
  }
}

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('browser persistence', () => {
  it('saves and restores a game', () => {
    const storage = new MemoryStorage();
    const engine = createEngine({ definition: definitionDocument('strips') });
    engine.dispatchAction(engine.legalActions()[0]);
    expect(saveState(engine, storage)).toEqual({ ok: true });
    const other = createEngine({ definition: definitionDocument('strips') });
    expect(restoreState(other, storage)).toEqual({ restored: true });
    expect(other.exportState().board).toEqual(engine.exportState().board);
  });

  it('a missing entry is not an error; corrupt or stale entries keep the game and the data', () => {
    const storage = new MemoryStorage();
    const engine = createEngine({ definition: definitionDocument('strips') });
    expect(restoreState(engine, storage)).toEqual({ restored: false });
    const key = storageKey(engine.definition?.id ?? '');
    const before = engine.exportState();

    storage.items.set(key, '{not json');
    const corrupt = restoreState(engine, storage);
    expect(corrupt.restored).toBe(false);
    expect(!corrupt.restored && corrupt.error?.code).toBe('storage');

    storage.items.set(key, JSON.stringify({ ...before, version: 99 }));
    const stale = restoreState(engine, storage);
    expect(!stale.restored && stale.error?.code).toBe('invalid');
    expect(storage.items.get(key)).toContain('"version":99');
    expect(engine.exportState()).toEqual(before);
  });

  it('a full or unavailable storage is reported without throwing', () => {
    const storage = new MemoryStorage();
    storage.failWrites = true;
    const engine = createEngine({ definition: definitionDocument('swap') });
    const result = saveState(engine, storage);
    expect(result.ok).toBe(false);
    expect(!result.ok && result.error.code).toBe('storage');

    vi.stubGlobal('localStorage', {
      getItem: () => null,
      setItem: () => {
        throw new Error('SecurityError');
      },
      removeItem: () => undefined,
    });
    expect(browserStorage()).toBeNull();
  });

  it('autoPersist debounces writes and flushes on stop', () => {
    vi.useFakeTimers();
    const storage = new MemoryStorage();
    const setItem = vi.spyOn(storage, 'setItem');
    const engine = createEngine({ definition: definitionDocument('swap') });
    const stop = autoPersist(engine, storage, { debounceMs: 100 });
    engine.dispatchAction(engine.legalActions()[0]);
    engine.dispatchAction(engine.legalActions()[0]);
    expect(setItem).not.toHaveBeenCalled();
    vi.advanceTimersByTime(100);
    expect(setItem).toHaveBeenCalledTimes(1);
    engine.dispatchAction(engine.legalActions()[0]);
    stop();
    expect(setItem).toHaveBeenCalledTimes(2);
    engine.dispatchAction(engine.legalActions()[0]);
    vi.advanceTimersByTime(1000);
    expect(setItem).toHaveBeenCalledTimes(2);
  });
});
