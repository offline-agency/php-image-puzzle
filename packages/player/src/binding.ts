import { createPlayer } from './player.js';
import type { Player, PlayerEventMap, PlayerEventType, PlayerOptions } from './player.js';
import type { Messages } from './messages.js';
import { followThemeClass } from './theme.js';

/** Engine events forwarded by the framework adapters, in dispatch order. */
export const PLAYER_EVENTS = [
  'ready',
  'move',
  'state-change',
  'progress',
  'complete',
  'error',
] as const satisfies readonly PlayerEventType[];

export type PlayerHandlers = { readonly [K in PlayerEventType]?: (payload: PlayerEventMap[K]) => void };

type CreationKey = 'controls' | 'persist' | 'motion' | 'pauseWhenHidden' | 'fetch' | 'clock' | 'randomSeed' | 'onListenerError';

/** Options read once, when the player is created; change them by remounting. */
export type PlayerCreationOptions = { readonly [K in CreationKey]?: PlayerOptions[K] | undefined };

/** Options applied on every update; only the ones that changed take effect. */
export interface PlayerBindingProps {
  /** Definition document. Wins over `src`; compared by identity. */
  readonly definition?: unknown;
  /** Definition URL. */
  readonly src?: string | undefined;
  /**
   * Saved state restored by the first load after mount (null: a fresh game). Later sources start
   * fresh games; restore into one with `player.load(source, { state })` or by remounting.
   */
  readonly initialState?: unknown;
  /** Base URL of an object definition's assets. */
  readonly baseUrl?: string | undefined;
  readonly locale?: string | undefined;
  readonly messages?: Partial<Messages> | undefined;
  /** Forces a palette; by default the system preference applies. */
  readonly theme?: 'light' | 'dark' | undefined;
  /** Follows a class-based dark mode on <html> (for example "dark"). */
  readonly themeClass?: string | undefined;
}

export type PlayerBindingOptions = PlayerBindingProps & PlayerCreationOptions;

export interface PlayerBinding {
  readonly player: Player;
  /** Applies changed props: a new source loads it, a new locale relabels, a new theme restyles. Creation options are ignored. */
  update(props: PlayerBindingProps): void;
  destroy(): void;
}

function playerOptions(options: PlayerBindingOptions): PlayerOptions {
  return {
    ...(options.controls !== undefined ? { controls: options.controls } : {}),
    ...(options.persist !== undefined ? { persist: options.persist } : {}),
    ...(options.motion !== undefined ? { motion: options.motion } : {}),
    ...(options.pauseWhenHidden !== undefined ? { pauseWhenHidden: options.pauseWhenHidden } : {}),
    ...(options.fetch !== undefined ? { fetch: options.fetch } : {}),
    ...(options.clock !== undefined ? { clock: options.clock } : {}),
    ...(options.randomSeed !== undefined ? { randomSeed: options.randomSeed } : {}),
    ...(options.onListenerError !== undefined ? { onListenerError: options.onListenerError } : {}),
    ...(options.locale !== undefined ? { locale: options.locale } : {}),
    ...(options.messages !== undefined ? { messages: options.messages } : {}),
  };
}

/**
 * The lifecycle shared by the Vue and React adapters: creates a player in
 * `target`, forwards engine events to the current handlers (read on every
 * event, so they can change without resubscribing) and applies prop changes
 * without reloading the game for anything but a new source.
 */
export function bindPlayer(target: HTMLElement, options: PlayerBindingOptions, handlers: () => PlayerHandlers): PlayerBinding {
  const player = createPlayer(target, playerOptions(options));
  const unsubscribers = [
    player.subscribe('ready', (payload) => handlers().ready?.(payload)),
    player.subscribe('move', (payload) => handlers().move?.(payload)),
    player.subscribe('state-change', (payload) => handlers()['state-change']?.(payload)),
    player.subscribe('progress', (payload) => handlers().progress?.(payload)),
    player.subscribe('complete', (payload) => handlers().complete?.(payload)),
    player.subscribe('error', (payload) => handlers().error?.(payload)),
  ];
  let source: unknown = undefined;
  let locale = options.locale;
  let messages = options.messages;
  let theme: string | undefined;
  let themeClass: string | undefined;
  let stopTheme: (() => void) | null = null;
  let destroyed = false;

  function update(next: PlayerBindingProps): void {
    if (destroyed) return;
    const nextSource = next.definition ?? next.src;
    if (nextSource !== undefined && nextSource !== source) {
      // A saved state belongs to the puzzle it was saved from: only the first load restores it.
      const first = source === undefined;
      source = nextSource;
      player
        .load(nextSource, {
          ...(first && next.initialState !== undefined && next.initialState !== null ? { state: next.initialState } : {}),
          ...(next.baseUrl !== undefined ? { baseUrl: next.baseUrl } : {}),
        })
        // Failures are reported through the "error" event.
        .catch(() => undefined);
    }
    if (next.locale !== locale || next.messages !== messages) {
      locale = next.locale;
      messages = next.messages;
      player.setLocale(locale, messages);
    }
    if (next.theme !== theme) {
      theme = next.theme;
      if (theme === undefined) player.element.removeAttribute('theme');
      else player.element.setAttribute('theme', theme);
    }
    if (next.themeClass !== themeClass) {
      themeClass = next.themeClass;
      stopTheme?.();
      stopTheme = themeClass === undefined || themeClass === '' ? null : followThemeClass(player.element, themeClass);
    }
  }

  update(options);

  return {
    player,
    update,
    destroy() {
      if (destroyed) return;
      destroyed = true;
      stopTheme?.();
      for (const unsubscribe of unsubscribers) unsubscribe();
      player.destroy();
    },
  };
}
