import type {} from '@offline-agency/image-puzzle-player/global';
// Type-level consumer of every public entry, compiled against the packed tarballs.
import { createEngine, parseDefinition } from '@offline-agency/image-puzzle-engine';
import type { Action, GameStateDocument, PuzzleEngine } from '@offline-agency/image-puzzle-engine';
import { DEFAULT_TAG, ELEMENT_EVENTS, createPlayer, defineImagePuzzlePlayer } from '@offline-agency/image-puzzle-player';
import type { ImagePuzzlePlayerElement, Player, PlayerOptions } from '@offline-agency/image-puzzle-player';
import { ImagePuzzlePlayerElement as Registered } from '@offline-agency/image-puzzle-player/define';
import * as bundle from '@offline-agency/image-puzzle-player/bundle';

export function mount(host: HTMLElement, options: PlayerOptions): Player {
  const player = createPlayer(host, options);
  player.subscribe('complete', ({ moves, elapsedMs }) => {
    console.log(moves, elapsedMs);
  });
  return player;
}

export function element(): ImagePuzzlePlayerElement {
  defineImagePuzzlePlayer();
  const node = document.createElement(DEFAULT_TAG);
  node.addEventListener(ELEMENT_EVENTS.complete, () => undefined);
  return node;
}

export function headless(definition: unknown): GameStateDocument {
  const engine: PuzzleEngine = createEngine({ definition: parseDefinition(definition) });
  const action: Action | undefined = engine.legalActions()[0];
  if (action !== undefined) engine.dispatchAction(action);
  return engine.exportState();
}

export const registered: CustomElementConstructor | undefined = Registered;
export const viaBundle: typeof createPlayer = bundle.createPlayer;
export const viaGlobal: typeof createPlayer = ImagePuzzle.createPlayer;
