import { PuzzleError, autoPersist, browserStorage, createEngine, restoreState, saveState } from '@offline-agency/image-puzzle-engine';
import type {
  Action,
  ActionResult,
  EngineEventMap,
  EngineEventType,
  EngineSnapshot,
  GameStateDocument,
  PuzzleEngine,
  StateStorage,
} from '@offline-agency/image-puzzle-engine';
import { el, resolveAsset } from './dom.js';
import { format, formatTime, resolveMessages } from './messages.js';
import type { Messages } from './messages.js';
import { styles } from './styles.js';
import { createGridView } from './views/grid.js';
import { createJigsawView } from './views/jigsaw.js';
import { createNonogramView } from './views/nonogram.js';
import type { ModeView, ViewContext } from './views/types.js';

/** Largest definition document fetched from `src` (matches the PHP GameDefinition::MAX_JSON_BYTES). */
export const MAX_DEFINITION_BYTES = 4 * 1024 * 1024;
const ZOOM_LEVELS = [1, 1.5, 2, 3, 4];

export type PlayerEventMap = EngineEventMap;
export type PlayerEventType = EngineEventType;
export type PlayerListener<K extends PlayerEventType> = (payload: PlayerEventMap[K]) => void;

/** Which surface is shown: no puzzle yet, a pending load, a failure, or a playable board. */
export type PlayerView = 'empty' | 'loading' | 'error' | 'ready';

export interface PersistOptions {
  /** Defaults to window.localStorage when usable; persistence is skipped when unavailable. */
  readonly storage?: StateStorage | null;
  readonly prefix?: string;
  readonly debounceMs?: number;
}

export interface LoadOptions {
  /** Saved state to restore with this definition instead of any persisted one. */
  readonly state?: unknown;
  /** Base URL for definition-relative assets of an object definition. */
  readonly baseUrl?: string;
}

export interface PlayerOptions {
  /** Definition document (object) to load immediately. Wins over `src`. */
  readonly definition?: unknown;
  /** URL of a definition document to fetch and load immediately. */
  readonly src?: string;
  /** Saved state for the initial load. */
  readonly state?: unknown;
  /** Base URL for assets of an object `definition`; defaults to the document URL. */
  readonly baseUrl?: string;
  readonly locale?: string;
  readonly messages?: Partial<Messages>;
  /** Show the built-in toolbar (default true). Hosts can drive the API instead. */
  readonly controls?: boolean;
  /** Save and restore progress per definition ID. */
  readonly persist?: boolean | PersistOptions;
  /** Motion preference; 'auto' follows prefers-reduced-motion. */
  readonly motion?: 'auto' | 'reduce' | 'full';
  /** Pause the timer while the document is hidden (default true). */
  readonly pauseWhenHidden?: boolean;
  readonly fetch?: (input: string, init: RequestInit) => Promise<Response>;
  readonly clock?: () => number;
  readonly randomSeed?: () => number;
  readonly onListenerError?: (error: unknown) => void;
}

export interface Player {
  /** The element hosting the shadow root (created for an element target, the custom element otherwise). */
  readonly element: HTMLElement;
  readonly engine: PuzzleEngine;
  readonly view: PlayerView;
  /** Loads a definition object or fetches a URL. A load superseded by a newer one resolves without effect. */
  load(source: unknown, options?: LoadOptions): Promise<void>;
  start(): void;
  pause(): void;
  reset(): void;
  shuffle(seed?: number): void;
  undo(): boolean;
  redo(): boolean;
  /** Highlights and returns the hinted action (counts as a used hint). */
  hint(): Action | null;
  dispatchAction(action: unknown): ActionResult;
  exportState(): GameStateDocument;
  importState(state: unknown): void;
  snapshot(): EngineSnapshot;
  setLocale(locale: string | undefined, messages?: Partial<Messages>): void;
  subscribe<K extends PlayerEventType>(type: K, listener: PlayerListener<K>): () => void;
  /** Stops timers and flushes persistence while detached; resume() undoes it. Used by the custom element. */
  suspend(): void;
  resume(): void;
  destroy(): void;
}

const players = new WeakMap<Node, Player>();

function documentBase(doc: Document): string {
  return doc.baseURI;
}

/**
 * Mounts a player. An element target receives a new child element with an
 * open shadow root; a ShadowRoot target (the custom element) is rendered into
 * directly. A target can host one live player at a time.
 */
export function createPlayer(target: HTMLElement | ShadowRoot, options: PlayerOptions = {}): Player {
  if (players.has(target)) {
    throw new PuzzleError('invalid', 'This element already hosts an image puzzle player; destroy it first.');
  }
  const doc = target.ownerDocument;
  let shadow: ShadowRoot;
  let host: HTMLElement;
  const ownsHost = !(target instanceof ShadowRoot);
  if (target instanceof ShadowRoot) {
    shadow = target;
    host = target.host as HTMLElement;
  } else {
    host = el(doc, 'div', { class: 'image-puzzle-player' });
    target.append(host);
    shadow = host.attachShadow({ mode: 'open' });
  }

  let locale = options.locale;
  let messages = resolveMessages(locale, options.messages);
  const onListenerError =
    options.onListenerError ??
    ((error: unknown) => {
      queueMicrotask(() => {
        throw error;
      });
    });
  const engine = createEngine({
    ...(options.clock ? { clock: options.clock } : {}),
    ...(options.randomSeed ? { randomSeed: options.randomSeed } : {}),
    onListenerError,
  });
  const fetchImpl = options.fetch ?? ((input: string, init: RequestInit) => fetch(input, init));
  const persist = options.persist === undefined || options.persist === false ? null : options.persist === true ? {} : options.persist;
  const storage = persist === null ? null : persist.storage === undefined ? browserStorage() : persist.storage;
  const prefix = persist?.prefix;

  // --- DOM skeleton -------------------------------------------------------
  const style = el(doc, 'style', {}, styles);
  const root = el(doc, 'div', { class: 'root', part: 'root' });
  const toolbar = el(doc, 'div', { class: 'toolbar', part: 'toolbar', role: 'group' });
  const control = (name: string): HTMLButtonElement =>
    el(doc, 'button', { type: 'button', class: 'control', part: `control control-${name}`, 'data-control': name });
  const buttons = {
    start: control('start'),
    undo: control('undo'),
    redo: control('redo'),
    reset: control('reset'),
    shuffle: control('shuffle'),
    hint: control('hint'),
    preview: control('preview'),
    zoomOut: control('zoom-out'),
    zoomIn: control('zoom-in'),
  };
  toolbar.append(...Object.values(buttons));
  toolbar.hidden = options.controls === false;
  const status = el(doc, 'div', { class: 'status', part: 'status' });
  const movesText = el(doc, 'span', { 'data-status': 'moves' });
  const timeText = el(doc, 'span', { 'data-status': 'time' });
  const progressText = el(doc, 'span', { 'data-status': 'progress' });
  const hintsText = el(doc, 'span', { 'data-status': 'hints' });
  status.append(movesText, timeText, progressText, hintsText);
  const message = el(doc, 'p', { class: 'message', part: 'message', role: 'status' });
  const slot = el(doc, 'div', { class: 'slot' });
  const live = el(doc, 'div', { class: 'sr-only', 'aria-live': 'polite', 'aria-atomic': 'true' });
  root.append(toolbar, status, message, slot, live);
  shadow.replaceChildren(style, root);

  // --- state ----------------------------------------------------------------
  const listeners = new Map<PlayerEventType, Set<(payload: never) => void>>();
  let view: ModeView | null = null;
  let viewState: PlayerView = 'empty';
  let baseUrl = options.baseUrl ?? documentBase(doc);
  let loadToken = 0;
  let controller: AbortController | null = null;
  let ticker: ReturnType<typeof setInterval> | null = null;
  let preview = false;
  let zoomIndex = 0;
  let destroyed = false;
  let suspended = false;
  let pausedByPlayer = false;
  let stopPersist: (() => void) | null = storage === null ? null : autoPersist(engine, storage, persistOptions());

  function persistOptions(): { prefix?: string; debounceMs?: number; onError: (error: PuzzleError) => void } {
    return {
      ...(prefix !== undefined ? { prefix } : {}),
      ...(persist?.debounceMs !== undefined ? { debounceMs: persist.debounceMs } : {}),
      onError: (error) => {
        emit('error', { code: error.code, message: error.message });
      },
    };
  }

  function emit<K extends PlayerEventType>(type: K, payload: PlayerEventMap[K]): void {
    for (const listener of [...(listeners.get(type) ?? [])]) {
      try {
        (listener as PlayerListener<K>)(payload);
      } catch (error) {
        onListenerError(error);
      }
    }
  }

  function announce(text: string): void {
    if (text === '') return;
    // Re-setting identical text is not re-announced; clear first.
    live.textContent = '';
    live.textContent = text;
  }

  function setMotion(): void {
    if (options.motion === 'reduce' || options.motion === 'full') host.setAttribute('data-motion', options.motion);
  }

  function showMessage(kind: 'empty' | 'loading' | 'error' | null, text = ''): void {
    message.hidden = kind === null;
    message.dataset['kind'] = kind ?? '';
    message.textContent = text;
  }

  function setView(next: PlayerView, text = ''): void {
    viewState = next;
    host.setAttribute('data-state', next);
    root.setAttribute('aria-busy', String(next === 'loading'));
    const m = messages;
    if (next === 'empty') showMessage('empty', m.empty);
    else if (next === 'loading') showMessage('loading', m.loading);
    else if (next === 'error') showMessage('error', format(m.loadError, { message: text }));
    else showMessage(null);
    slot.hidden = next === 'loading' || view === null;
    renderControls();
  }

  function renderTexts(): void {
    const m = messages;
    if (locale === undefined) root.removeAttribute('lang');
    else root.lang = locale;
    toolbar.setAttribute('aria-label', m.controls);
    buttons.undo.textContent = m.undo;
    buttons.redo.textContent = m.redo;
    buttons.reset.textContent = m.reset;
    buttons.shuffle.textContent = m.shuffle;
    buttons.hint.textContent = m.hint;
    buttons.preview.textContent = m.preview;
    buttons.zoomOut.textContent = m.zoomOut;
    buttons.zoomIn.textContent = m.zoomIn;
  }

  function renderControls(): void {
    const definition = engine.status === 'destroyed' ? null : engine.definition;
    const loaded = definition !== null && viewState !== 'loading';
    const st = engine.status;
    const capabilities = definition?.capabilities;
    buttons.start.hidden = !loaded;
    buttons.start.textContent = st === 'running' ? messages.pause : st === 'paused' ? messages.resume : messages.start;
    buttons.start.disabled = st === 'complete';
    buttons.undo.hidden = !loaded || capabilities?.undo !== true;
    buttons.undo.disabled = !loaded || !engine.canUndo();
    buttons.redo.hidden = !loaded || capabilities?.redo !== true;
    buttons.redo.disabled = !loaded || !engine.canRedo();
    buttons.reset.hidden = !loaded || capabilities?.reset !== true;
    buttons.shuffle.hidden = !loaded || capabilities?.shuffle !== true;
    buttons.hint.hidden = !loaded || capabilities?.hint !== true;
    buttons.hint.disabled = st === 'complete';
    buttons.preview.hidden = !loaded || !definition.assistance.preview;
    buttons.preview.setAttribute('aria-pressed', String(preview));
    const zoomable = loaded && definition.kind === 'jigsaw';
    buttons.zoomOut.hidden = !zoomable;
    buttons.zoomIn.hidden = !zoomable;
    buttons.zoomOut.disabled = zoomIndex === 0;
    buttons.zoomIn.disabled = zoomIndex === ZOOM_LEVELS.length - 1;
    status.hidden = !loaded;
    renderStatus();
  }

  function renderStatus(): void {
    if (engine.status === 'destroyed' || engine.definition === null) return;
    const snap = engine.snapshot();
    movesText.textContent = format(messages.moves, { n: snap.moves });
    timeText.textContent = format(messages.time, { time: formatTime(snap.elapsedMs) });
    progressText.textContent = format(messages.progress, { percent: Math.floor(snap.progress * 100) });
    hintsText.hidden = !engine.definition.capabilities.hint;
    hintsText.textContent = format(messages.hints, { n: snap.hintsUsed });
  }

  function syncTicker(): void {
    const running = engine.status === 'running' && !suspended;
    if (running && ticker === null) {
      ticker = setInterval(renderStatus, 1000);
    } else if (!running && ticker !== null) {
      clearInterval(ticker);
      ticker = null;
    }
  }

  function buildView(): void {
    view?.destroy();
    view = null;
    preview = false;
    zoomIndex = 0;
    const definition = engine.definition;
    if (definition === null) return;
    const context: ViewContext = {
      doc,
      definition,
      engine,
      messages: () => messages,
      asset: (path) => resolveAsset(path, baseUrl),
      announce,
      dispatch: (action) => engine.dispatchAction(action).ok,
      assetError: (url) => {
        emit('error', { code: 'load', message: `Could not load ${url}` });
        setView('error', `Could not load ${url}`);
      },
    };
    view =
      definition.kind === 'jigsaw'
        ? createJigsawView(context)
        : definition.kind === 'nonogram'
          ? createNonogramView(context)
          : createGridView(context);
    slot.replaceChildren(view.element);
  }

  // --- engine wiring ----------------------------------------------------------
  const unsubscribers = [
    engine.subscribe('state-change', (change) => {
      if (change.reason === 'load') {
        buildView();
      } else {
        view?.update(change.state.board, change.reason !== 'action');
      }
      renderControls();
      syncTicker();
      emit('state-change', change);
    }),
    engine.subscribe('complete', (done) => {
      announce(format(messages.complete, { moves: done.moves, time: formatTime(done.elapsedMs) }));
      emit('complete', done);
    }),
    engine.subscribe('ready', (payload) => {
      emit('ready', payload);
    }),
    engine.subscribe('move', (payload) => {
      emit('move', payload);
    }),
    engine.subscribe('progress', (payload) => {
      emit('progress', payload);
    }),
    engine.subscribe('error', (payload) => {
      emit('error', payload);
    }),
  ];

  // --- controls -----------------------------------------------------------------
  function onToolbarClick(event: MouseEvent): void {
    const button = (event.target as Element | null)?.closest('button[data-control]');
    if (!(button instanceof HTMLButtonElement) || button.disabled || engine.definition === null) return;
    switch (button.dataset['control']) {
      case 'start':
        if (engine.status === 'running') engine.pause();
        else engine.start();
        break;
      case 'undo':
        engine.undo();
        break;
      case 'redo':
        engine.redo();
        break;
      case 'reset':
        engine.reset();
        break;
      case 'shuffle':
        engine.shuffle();
        break;
      case 'hint':
        player.hint();
        break;
      case 'preview':
        preview = !preview;
        view?.setPreview(preview);
        renderControls();
        break;
      case 'zoom-in':
      case 'zoom-out':
        zoomIndex = Math.min(ZOOM_LEVELS.length - 1, Math.max(0, zoomIndex + (button.dataset['control'] === 'zoom-in' ? 1 : -1)));
        view?.setZoom?.(ZOOM_LEVELS[zoomIndex] ?? 1);
        renderControls();
        break;
    }
  }
  toolbar.addEventListener('click', onToolbarClick);

  function onVisibility(): void {
    if (doc.visibilityState === 'hidden' && engine.status === 'running') {
      engine.pause();
      pausedByPlayer = true;
    } else if (doc.visibilityState === 'visible' && pausedByPlayer) {
      pausedByPlayer = false;
      if (engine.status === 'paused') engine.start();
    }
  }
  const watchVisibility = options.pauseWhenHidden !== false;
  if (watchVisibility) doc.addEventListener('visibilitychange', onVisibility);

  // --- loading ------------------------------------------------------------------------
  async function fetchDefinition(url: string, signal: AbortSignal): Promise<{ data: unknown; base: string }> {
    let response: Response;
    try {
      response = await fetchImpl(url, { signal, credentials: 'same-origin', headers: { Accept: 'application/json' } });
    } catch (error) {
      if (signal.aborted) throw error;
      throw new PuzzleError('load', `Could not fetch ${url}`, { cause: error });
    }
    if (!response.ok) {
      throw new PuzzleError('load', `Fetching ${url} failed with HTTP ${response.status}`);
    }
    const declared = Number(response.headers.get('content-length') ?? '0');
    if (declared > MAX_DEFINITION_BYTES) {
      throw new PuzzleError('load', `${url} exceeds ${MAX_DEFINITION_BYTES} bytes`);
    }
    const text = await response.text();
    if (text.length > MAX_DEFINITION_BYTES) {
      throw new PuzzleError('load', `${url} exceeds ${MAX_DEFINITION_BYTES} bytes`);
    }
    try {
      return { data: JSON.parse(text) as unknown, base: response.url === '' ? url : response.url };
    } catch (error) {
      throw new PuzzleError('load', `${url} is not valid JSON`, { cause: error });
    }
  }

  /** A newer load started, or the player was destroyed, while this one awaited. */
  function stale(token: number): boolean {
    return token !== loadToken || destroyed;
  }

  async function load(source: unknown, loadOptions: LoadOptions = {}): Promise<void> {
    if (destroyed) throw new PuzzleError('destroyed', 'The player was destroyed.');
    const token = ++loadToken;
    controller?.abort();
    const current = new AbortController();
    controller = current;
    setView('loading');
    let data: unknown;
    let base: string;
    try {
      if (typeof source === 'string') {
        const url = new URL(source, documentBase(doc)).href;
        ({ data, base } = await fetchDefinition(url, current.signal));
      } else {
        data = source;
        base = loadOptions.baseUrl ?? options.baseUrl ?? documentBase(doc);
      }
    } catch (error) {
      if (stale(token)) return;
      const failure = error instanceof PuzzleError ? error : new PuzzleError('load', String(error), { cause: error });
      emit('error', { code: failure.code, message: failure.message });
      setView('error', failure.message);
      throw failure;
    }
    if (stale(token)) return;
    controller = null;
    const previousBase = baseUrl;
    baseUrl = base;
    try {
      // The engine validates everything and emits `error` itself on rejection.
      engine.load(data, loadOptions.state);
    } catch (error) {
      baseUrl = previousBase;
      setView('error', error instanceof Error ? error.message : String(error));
      throw error;
    }
    setView('ready');
    if (loadOptions.state === undefined && storage !== null) {
      const restored = restoreState(engine, storage, prefix);
      // Rejected imports are already reported by the engine's error event.
      if (!restored.restored && restored.error?.code === 'storage') {
        emit('error', { code: restored.error.code, message: restored.error.message });
      }
    }
  }

  const player: Player = {
    element: host,
    engine,
    get view() {
      return viewState;
    },
    load,
    start: () => {
      engine.start();
    },
    pause: () => {
      engine.pause();
    },
    reset: () => {
      engine.reset();
    },
    shuffle: (seed) => {
      engine.shuffle(seed);
    },
    undo: () => engine.undo(),
    redo: () => engine.redo(),
    hint() {
      const action = engine.hint();
      if (action === null) {
        announce(messages.noHint);
      } else {
        view?.showHint(action);
      }
      renderStatus();
      return action;
    },
    dispatchAction: (action) => engine.dispatchAction(action),
    exportState: () => engine.exportState(),
    importState: (state) => {
      engine.importState(state);
    },
    snapshot: () => engine.snapshot(),
    setLocale(nextLocale, overrides) {
      locale = nextLocale;
      messages = resolveMessages(locale, overrides ?? options.messages);
      renderTexts();
      if (engine.definition !== null && viewState !== 'loading') buildView();
      setView(viewState);
    },
    subscribe(type, listener) {
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
    suspend() {
      if (destroyed || suspended) return;
      suspended = true;
      if (engine.status === 'running') {
        engine.pause();
        pausedByPlayer = true;
      }
      syncTicker();
      if (storage !== null && engine.definition !== null) saveState(engine, storage, prefix);
      if (watchVisibility) doc.removeEventListener('visibilitychange', onVisibility);
    },
    resume() {
      if (destroyed || !suspended) return;
      suspended = false;
      if (watchVisibility) doc.addEventListener('visibilitychange', onVisibility);
      if (pausedByPlayer && engine.status === 'paused' && doc.visibilityState !== 'hidden') {
        pausedByPlayer = false;
        engine.start();
      }
      syncTicker();
    },
    destroy() {
      if (destroyed) return;
      destroyed = true;
      loadToken++;
      controller?.abort();
      stopPersist?.();
      stopPersist = null;
      if (ticker !== null) clearInterval(ticker);
      ticker = null;
      if (watchVisibility) doc.removeEventListener('visibilitychange', onVisibility);
      toolbar.removeEventListener('click', onToolbarClick);
      view?.destroy();
      view = null;
      for (const unsubscribe of unsubscribers) unsubscribe();
      listeners.clear();
      engine.destroy();
      shadow.replaceChildren();
      host.removeAttribute('data-state');
      if (ownsHost) host.remove();
      players.delete(target);
    },
  };
  players.set(target, player);

  setMotion();
  renderTexts();
  setView('empty');
  if (options.definition !== undefined || options.src !== undefined) {
    const initial = options.definition ?? options.src;
    const loadOptions: LoadOptions = options.state !== undefined ? { state: options.state } : {};
    // Failures are reported through the error event and the error view.
    load(initial, loadOptions).catch(() => undefined);
  }
  return player;
}
