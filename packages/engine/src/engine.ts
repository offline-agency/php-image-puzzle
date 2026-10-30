import { parseDefinition } from './definition.js';
import { IllegalActionError, PuzzleError } from './errors.js';
import type { PuzzleErrorCode } from './errors.js';
import { MAX_SEED } from './prng.js';
import { rulesFor } from './rules/index.js';
import { HISTORY_LIMIT, parseState, stateDocument } from './state.js';
import type { ParsedState } from './state.js';
import type { Action, Board, GameDefinition, GameKind, GameStateDocument } from './types.js';

export type EngineStatus = 'empty' | 'idle' | 'running' | 'paused' | 'complete' | 'destroyed';

export type StateChangeReason = 'load' | 'import' | 'action' | 'undo' | 'redo' | 'reset' | 'shuffle' | 'hint' | 'start' | 'pause';

/** Payloads of the semantic events (docs/schemas/game/v1/events.schema.json). */
export interface EngineEventMap {
  ready: { readonly definitionId: string; readonly kind: GameKind; readonly state: GameStateDocument };
  move: { readonly action: Action; readonly state: GameStateDocument };
  'state-change': { readonly reason: StateChangeReason; readonly status: EngineStatus; readonly state: GameStateDocument };
  progress: { readonly progress: number; readonly moves: number; readonly elapsedMs: number };
  complete: { readonly definitionId: string; readonly moves: number; readonly elapsedMs: number; readonly hintsUsed: number };
  error: { readonly code: PuzzleErrorCode; readonly message: string };
}

export type EngineEventType = keyof EngineEventMap;
export type EngineListener<K extends EngineEventType> = (payload: EngineEventMap[K]) => void;

export type ActionResult = { readonly ok: true } | { readonly ok: false; readonly error: PuzzleError };

export interface EngineSnapshot {
  readonly status: EngineStatus;
  readonly board: Board | null;
  readonly moves: number;
  readonly elapsedMs: number;
  readonly hintsUsed: number;
  readonly progress: number;
  readonly completed: boolean;
  readonly canUndo: boolean;
  readonly canRedo: boolean;
}

export interface EngineOptions {
  /** Definition document to load immediately (validated). */
  readonly definition?: unknown;
  /** Saved state to restore with the definition (validated). */
  readonly state?: unknown;
  /** Monotonic millisecond clock; defaults to performance.now(). */
  readonly clock?: () => number;
  /** Seed source for shuffle() without a seed; defaults to crypto.getRandomValues. */
  readonly randomSeed?: () => number;
  /**
   * Receives errors thrown by event listeners. Listeners never break the
   * engine or each other; by default the error is rethrown asynchronously so
   * it reaches the host's global error reporting.
   */
  readonly onListenerError?: (error: unknown) => void;
}

/**
 * Headless engine. Every adapter (plain JS, Web Component, Livewire,
 * Filament, Vue, React) delegates to one instance per player.
 */
export interface PuzzleEngine {
  readonly definition: GameDefinition | null;
  readonly status: EngineStatus;
  load(definition: unknown, state?: unknown): void;
  start(): void;
  pause(): void;
  reset(): void;
  shuffle(seed?: number): void;
  dispatchAction(action: unknown): ActionResult;
  legalActions(): Action[];
  hint(): Action | null;
  undo(): boolean;
  redo(): boolean;
  canUndo(): boolean;
  canRedo(): boolean;
  exportState(): GameStateDocument;
  importState(state: unknown): void;
  snapshot(): EngineSnapshot;
  subscribe<K extends EngineEventType>(type: K, listener: EngineListener<K>): () => void;
  destroy(): void;
}

interface Session {
  readonly definition: GameDefinition;
  state: ParsedState;
}

function defaultClock(): number {
  return typeof performance !== 'undefined' ? performance.now() : Date.now();
}

function defaultRandomSeed(): number {
  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    return crypto.getRandomValues(new Uint32Array(1))[0] ?? 0;
  }
  return Math.floor(Math.random() * MAX_SEED);
}

export function createEngine(options: EngineOptions = {}): PuzzleEngine {
  const clock = options.clock ?? defaultClock;
  const randomSeed = options.randomSeed ?? defaultRandomSeed;
  const onListenerError =
    options.onListenerError ??
    ((error: unknown) => {
      queueMicrotask(() => {
        throw error;
      });
    });
  const listeners = new Map<EngineEventType, Set<(payload: never) => void>>();
  let session: Session | null = null;
  let status: EngineStatus = 'empty';
  let elapsedBase = 0;
  let runningSince: number | null = null;

  function alive(): void {
    if (status === 'destroyed') {
      throw new PuzzleError('destroyed', 'The puzzle engine was destroyed.');
    }
  }

  function current(): Session {
    alive();
    if (session === null) {
      throw new PuzzleError('invalid', 'No puzzle is loaded.');
    }
    return session;
  }

  function elapsed(): number {
    return Math.floor(elapsedBase + (runningSince === null ? 0 : Math.max(0, clock() - runningSince)));
  }

  function stopTimer(): void {
    elapsedBase = elapsed();
    runningSince = null;
  }

  function emit<K extends EngineEventType>(type: K, payload: EngineEventMap[K]): void {
    const set = listeners.get(type);
    if (set === undefined) return;
    // Copy: listeners may unsubscribe (or subscribe) while we dispatch.
    for (const listener of [...set]) {
      try {
        (listener as EngineListener<K>)(payload);
      } catch (error) {
        onListenerError(error);
      }
    }
  }

  function documentOf(s: Session): GameStateDocument {
    return stateDocument(s.definition, { ...s.state, elapsedMs: elapsed() });
  }

  function progressOf(s: Session): number {
    return rulesFor(s.definition.kind).progress(s.definition, s.state.board);
  }

  function announce(reason: StateChangeReason): void {
    const s = current();
    emit('state-change', { reason, status, state: documentOf(s) });
    emit('progress', { progress: progressOf(s), moves: s.state.moves, elapsedMs: elapsed() });
  }

  function fail(error: unknown): never {
    if (error instanceof PuzzleError) {
      emit('error', { code: error.code, message: error.message });
      throw error;
    }
    const wrapped = new PuzzleError('invalid', error instanceof Error ? error.message : String(error), { cause: error });
    emit('error', { code: wrapped.code, message: wrapped.message });
    throw wrapped;
  }

  function begin(): void {
    if (status === 'idle' || status === 'paused') {
      status = 'running';
      runningSince = clock();
    }
  }

  function push(stack: readonly Board[], board: Board): Board[] {
    return [...stack, board].slice(-HISTORY_LIMIT);
  }

  function transition(s: Session, board: Board, past: readonly Board[], future: readonly Board[]): void {
    const completed = rulesFor(s.definition.kind).isComplete(s.definition, board);
    s.state = { ...s.state, board, past, future, moves: s.state.moves + 1, completed };
    if (completed) {
      stopTimer();
      status = 'complete';
    }
  }

  function finishIfComplete(s: Session): void {
    if (s.state.completed) {
      emit('complete', { definitionId: s.definition.id, moves: s.state.moves, elapsedMs: elapsed(), hintsUsed: s.state.hintsUsed });
    }
  }

  function fresh(definition: GameDefinition, board: Board): ParsedState {
    return { board, moves: 0, elapsedMs: 0, hintsUsed: 0, completed: false, past: [], future: [] };
  }

  const engine: PuzzleEngine = {
    get definition() {
      return session?.definition ?? null;
    },
    get status() {
      return status;
    },

    load(definitionData, stateData) {
      alive();
      let definition: GameDefinition;
      let state: ParsedState;
      try {
        // Validate everything before replacing the current game.
        definition = parseDefinition(definitionData);
        state = stateData === undefined ? fresh(definition, definition.initial) : parseState(definition, stateData);
      } catch (error) {
        fail(error);
      }
      session = { definition, state };
      elapsedBase = state.elapsedMs;
      runningSince = null;
      status = state.completed ? 'complete' : 'idle';
      announce('load');
      emit('ready', { definitionId: definition.id, kind: definition.kind, state: documentOf(session) });
    },

    start() {
      current();
      begin();
      announce('start');
    },

    pause() {
      current();
      if (status === 'running') {
        stopTimer();
        status = 'paused';
      }
      announce('pause');
    },

    reset() {
      const s = current();
      s.state = fresh(s.definition, s.definition.initial);
      elapsedBase = 0;
      runningSince = null;
      status = 'idle';
      announce('reset');
    },

    shuffle(seed) {
      const s = current();
      const value = seed ?? randomSeed();
      let board: Board;
      try {
        board = rulesFor(s.definition.kind).scramble(s.definition, value);
      } catch (error) {
        fail(error);
      }
      s.state = fresh(s.definition, board);
      elapsedBase = 0;
      runningSince = null;
      status = 'idle';
      announce('shuffle');
    },

    dispatchAction(action) {
      const s = current();
      if (s.state.completed) {
        return { ok: false, error: new IllegalActionError('The puzzle is already complete.') };
      }
      let board: Board;
      try {
        board = rulesFor(s.definition.kind).apply(s.definition, s.state.board, action);
      } catch (error) {
        if (error instanceof PuzzleError) {
          return { ok: false, error };
        }
        throw error;
      }
      begin();
      transition(s, board, push(s.state.past, s.state.board), []);
      emit('move', { action: structuredClone(action) as Action, state: documentOf(s) });
      announce('action');
      finishIfComplete(s);
      return { ok: true };
    },

    legalActions() {
      const s = current();
      return s.state.completed ? [] : rulesFor(s.definition.kind).legalActions(s.definition, s.state.board);
    },

    hint() {
      const s = current();
      if (!s.definition.capabilities.hint || s.state.completed) return null;
      const action = rulesFor(s.definition.kind).hint(s.definition, s.state.board);
      if (action === null) return null;
      s.state = { ...s.state, hintsUsed: s.state.hintsUsed + 1 };
      announce('hint');
      return action;
    },

    undo() {
      const s = current();
      if (!engine.canUndo()) return false;
      const past = [...s.state.past];
      const board = past.pop() as Board;
      begin();
      transition(s, board, past, push(s.state.future, s.state.board));
      announce('undo');
      finishIfComplete(s);
      return true;
    },

    redo() {
      const s = current();
      if (!engine.canRedo()) return false;
      const future = [...s.state.future];
      const board = future.pop() as Board;
      begin();
      transition(s, board, push(s.state.past, s.state.board), future);
      announce('redo');
      finishIfComplete(s);
      return true;
    },

    canUndo() {
      const s = current();
      return s.definition.capabilities.undo && !s.state.completed && s.state.past.length > 0;
    },

    canRedo() {
      const s = current();
      return s.definition.capabilities.redo && !s.state.completed && s.state.future.length > 0;
    },

    exportState() {
      return documentOf(current());
    },

    importState(data) {
      const s = current();
      let state: ParsedState;
      try {
        state = parseState(s.definition, data);
      } catch (error) {
        fail(error);
      }
      s.state = state;
      elapsedBase = state.elapsedMs;
      runningSince = null;
      status = state.completed ? 'complete' : 'idle';
      announce('import');
    },

    snapshot() {
      alive();
      if (session === null) {
        return { status, board: null, moves: 0, elapsedMs: 0, hintsUsed: 0, progress: 0, completed: false, canUndo: false, canRedo: false };
      }
      return {
        status,
        board: structuredClone(session.state.board),
        moves: session.state.moves,
        elapsedMs: elapsed(),
        hintsUsed: session.state.hintsUsed,
        progress: progressOf(session),
        completed: session.state.completed,
        canUndo: engine.canUndo(),
        canRedo: engine.canRedo(),
      };
    },

    subscribe(type, listener) {
      alive();
      let set = listeners.get(type);
      if (set === undefined) {
        set = new Set();
        listeners.set(type, set);
      }
      const entry = listener as (payload: never) => void;
      set.add(entry);
      return () => {
        listeners.get(type)?.delete(entry);
      };
    },

    destroy() {
      if (status === 'destroyed') return;
      stopTimer();
      listeners.clear();
      session = null;
      status = 'destroyed';
    },
  };

  if (options.definition !== undefined) {
    engine.load(options.definition, options.state);
  }
  return engine;
}
