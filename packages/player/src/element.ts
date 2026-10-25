import { PuzzleError } from '@offline-agency/image-puzzle-engine';
import type { Action, ActionResult, EngineSnapshot, GameStateDocument, PuzzleEngine } from '@offline-agency/image-puzzle-engine';
import { createPlayer } from './player.js';
import type { LoadOptions, Player, PlayerEventType, PlayerOptions } from './player.js';

export const DEFAULT_TAG = 'image-puzzle-player';

/** Every CustomEvent is named `puzzle-<engine event>` and carries the engine payload as `detail`. */
export const ELEMENT_EVENTS = {
  ready: 'puzzle-ready',
  move: 'puzzle-move',
  'state-change': 'puzzle-state-change',
  progress: 'puzzle-progress',
  complete: 'puzzle-complete',
  error: 'puzzle-error',
} as const satisfies Record<PlayerEventType, string>;

/** Options read when the element creates its player; `definition`, `src`, `state` and `locale` are separate. */
export type ElementOptions = Omit<PlayerOptions, 'definition' | 'src' | 'state' | 'locale'>;

/** Public surface of the custom element (instances of the class registered by defineImagePuzzlePlayer). */
export interface ImagePuzzlePlayerElement extends HTMLElement {
  /** Definition object; takes precedence over the `src` attribute. Set null to fall back to `src`. */
  definition: unknown;
  /** Saved state applied to the next automatic or explicit load, then cleared. */
  state: unknown;
  /** Player options, read when the player is created (first connection or after dispose()). */
  options: ElementOptions;
  /** Reflects the `src` attribute. */
  src: string | null;
  /** Reflects the `locale` attribute; updates labels live. */
  locale: string | null;
  readonly player: Player;
  readonly engine: PuzzleEngine;
  /** Loads the current source (definition, else src), or an explicit one. */
  load(source?: unknown, options?: LoadOptions): Promise<void>;
  start(): void;
  pause(): void;
  reset(): void;
  shuffle(seed?: number): void;
  undo(): boolean;
  redo(): boolean;
  hint(): Action | null;
  dispatchAction(action: unknown): ActionResult;
  exportState(): GameStateDocument;
  importState(state: unknown): void;
  snapshot(): EngineSnapshot;
  /** Destroys the player and releases every resource. A later connection starts a new player. */
  dispose(): void;
}

declare global {
  interface HTMLElementTagNameMap {
    'image-puzzle-player': ImagePuzzlePlayerElement;
  }
}

const UPGRADED_PROPERTIES = ['definition', 'state', 'options', 'src', 'locale'] as const;

/**
 * Registers the custom element. Idempotent: an already registered tag is
 * returned as is. Returns undefined outside a browser (SSR), where nothing
 * is touched.
 */
export function defineImagePuzzlePlayer(tag: string = DEFAULT_TAG): CustomElementConstructor | undefined {
  if (typeof customElements === 'undefined' || typeof HTMLElement === 'undefined') {
    return undefined;
  }
  const existing = customElements.get(tag);
  if (existing !== undefined) {
    return existing;
  }

  class Element extends HTMLElement implements ImagePuzzlePlayerElement {
    static observedAttributes = ['src', 'locale'];

    #player: Player | null = null;
    #definition: unknown = null;
    #state: unknown = undefined;
    #options: ElementOptions = {};
    /** Source of the last automatic load: the definition object or the resolved src string. */
    #loaded: unknown = undefined;
    #scheduled = false;

    constructor() {
      super();
      if (this.shadowRoot === null) this.attachShadow({ mode: 'open' });
    }

    connectedCallback(): void {
      // Properties set before the element was upgraded shadow the accessors.
      for (const name of UPGRADED_PROPERTIES) {
        if (Object.prototype.hasOwnProperty.call(this, name)) {
          const value: unknown = (this as Record<string, unknown>)[name];
          Reflect.deleteProperty(this, name);
          (this as Record<string, unknown>)[name] = value;
        }
      }
      if (this.#player === null) {
        this.#ensure();
      } else {
        this.#player.resume();
      }
      this.#schedule();
    }

    disconnectedCallback(): void {
      // A DOM move disconnects and reconnects in the same task; only suspend
      // when the element is still detached afterwards.
      queueMicrotask(() => {
        if (!this.isConnected) this.#player?.suspend();
      });
    }

    attributeChangedCallback(name: string, oldValue: string | null, value: string | null): void {
      if (oldValue === value) return;
      if (name === 'locale') {
        this.#player?.setLocale(value ?? undefined, this.#options.messages);
      } else {
        this.#schedule();
      }
    }

    get definition(): unknown {
      return this.#definition;
    }
    set definition(value: unknown) {
      this.#definition = value ?? null;
      this.#schedule();
    }

    get state(): unknown {
      return this.#state;
    }
    set state(value: unknown) {
      this.#state = value ?? undefined;
      this.#schedule(true);
    }

    get options(): ElementOptions {
      return this.#options;
    }
    set options(value: ElementOptions) {
      this.#options = { ...value };
    }

    get src(): string | null {
      return this.getAttribute('src');
    }
    set src(value: string | null) {
      if (value === null) this.removeAttribute('src');
      else this.setAttribute('src', value);
    }

    get locale(): string | null {
      return this.getAttribute('locale');
    }
    set locale(value: string | null) {
      if (value === null) this.removeAttribute('locale');
      else this.setAttribute('locale', value);
    }

    get player(): Player {
      return this.#ensure();
    }
    get engine(): PuzzleEngine {
      return this.#ensure().engine;
    }

    load(source?: unknown, options: LoadOptions = {}): Promise<void> {
      const player = this.#ensure();
      const target = source ?? this.#source();
      if (target === null) {
        return Promise.reject(new PuzzleError('invalid', 'No definition or src to load.'));
      }
      const state = options.state ?? this.#takeState();
      this.#loaded = source === undefined ? this.#sourceKey() : undefined;
      return player.load(target, { ...options, ...(state !== undefined ? { state } : {}) });
    }
    start(): void {
      this.#ensure().start();
    }
    pause(): void {
      this.#ensure().pause();
    }
    reset(): void {
      this.#ensure().reset();
    }
    shuffle(seed?: number): void {
      this.#ensure().shuffle(seed);
    }
    undo(): boolean {
      return this.#ensure().undo();
    }
    redo(): boolean {
      return this.#ensure().redo();
    }
    hint(): Action | null {
      return this.#ensure().hint();
    }
    dispatchAction(action: unknown): ActionResult {
      return this.#ensure().dispatchAction(action);
    }
    exportState(): GameStateDocument {
      return this.#ensure().exportState();
    }
    importState(state: unknown): void {
      this.#ensure().importState(state);
    }
    snapshot(): EngineSnapshot {
      return this.#ensure().snapshot();
    }
    dispose(): void {
      this.#player?.destroy();
      this.#player = null;
      this.#loaded = undefined;
    }

    #ensure(): Player {
      if (this.#player === null) {
        const shadow = this.shadowRoot as ShadowRoot;
        const locale = this.getAttribute('locale');
        const motion = this.getAttribute('motion');
        // Boolean/config attributes are read once, like `options`; properties win.
        const player = createPlayer(shadow, {
          ...(this.hasAttribute('persist') ? { persist: true } : {}),
          ...(this.hasAttribute('no-controls') ? { controls: false } : {}),
          ...(motion === 'reduce' || motion === 'full' ? { motion } : {}),
          ...this.#options,
          ...(locale !== null ? { locale } : {}),
        });
        for (const [type, name] of Object.entries(ELEMENT_EVENTS) as [PlayerEventType, string][]) {
          player.subscribe(type, (detail) => {
            this.dispatchEvent(new CustomEvent(name, { detail, bubbles: true, composed: true }));
          });
        }
        this.#player = player;
      }
      return this.#player;
    }

    #source(): unknown {
      return this.#definition ?? this.getAttribute('src');
    }

    #sourceKey(): unknown {
      const src = this.getAttribute('src');
      return this.#definition ?? (src === null ? null : new URL(src, this.ownerDocument.baseURI).href);
    }

    #takeState(): unknown {
      const state = this.#state;
      this.#state = undefined;
      return state;
    }

    /** Batches attribute/property changes made in the same task into one load. */
    #schedule(force = false): void {
      if (force) this.#loaded = undefined;
      if (this.#scheduled) return;
      this.#scheduled = true;
      queueMicrotask(() => {
        this.#scheduled = false;
        if (!this.isConnected || this.#player === null) return;
        const key = this.#sourceKey();
        if (key === null || key === this.#loaded) return;
        this.#loaded = key;
        const state = this.#takeState();
        // Failures surface as puzzle-error events and the error view.
        this.#player.load(this.#source(), state !== undefined ? { state } : {}).catch(() => undefined);
      });
    }
  }

  customElements.define(tag, Element);
  return Element;
}
