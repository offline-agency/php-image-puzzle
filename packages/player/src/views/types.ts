import type { Action, Board, GameDefinition, PuzzleEngine } from '@offline-agency/image-puzzle-engine';
import type { Messages } from '../messages.js';

/** What a mode view needs from the player; views never touch the engine's internals. */
export interface ViewContext {
  readonly doc: Document;
  readonly definition: GameDefinition;
  readonly engine: PuzzleEngine;
  readonly messages: () => Messages;
  /** Resolved URL of a definition-relative asset. */
  readonly asset: (path: string) => string;
  /** Polite screen-reader announcement. */
  readonly announce: (text: string) => void;
  /** Dispatches through the player; false when the engine rejected the action. */
  readonly dispatch: (action: Action) => boolean;
  /** Reports an asset failure (shown as the error state and emitted as an error event). */
  readonly assetError: (url: string) => void;
}

export interface ModeView {
  readonly element: HTMLElement;
  /** Re-renders from the engine's current board. Clears transient selection when `external`. */
  update(board: Board, external: boolean): void;
  showHint(action: Action): void;
  setPreview(visible: boolean): void;
  /** Jigsaw only: zoom factor ≥ 1. */
  setZoom?(zoom: number): void;
  focus(): void;
  destroy(): void;
}
