import type {} from '@offline-agency/image-puzzle-player/global';
// Type-level consumer of every public entry, compiled against the packed tarballs.
import { buildConfigDocument, createEngine, parseBuildConfig, parseDefinition } from '@offline-agency/image-puzzle-engine';
import type { Action, BuildConfigDocument, GameStateDocument, PuzzleEngine } from '@offline-agency/image-puzzle-engine';
import {
  CONFIGURATOR_TAG,
  DEFAULT_TAG,
  ELEMENT_EVENTS,
  createConfigurator,
  createPlayer,
  defineImagePuzzleConfigurator,
  defineImagePuzzlePlayer,
} from '@offline-agency/image-puzzle-player';
import type {
  Configurator,
  ImagePuzzleConfiguratorElement,
  ImagePuzzlePlayerElement,
  Player,
  PlayerOptions,
} from '@offline-agency/image-puzzle-player';
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

export function configurator(host: HTMLElement): BuildConfigDocument | null {
  const controller: Configurator = createConfigurator(host, {
    kinds: ['jigsaw'],
    onChange: ({ value }) => {
      console.log(value);
    },
  });
  defineImagePuzzleConfigurator();
  const node: ImagePuzzleConfiguratorElement = document.createElement(CONFIGURATOR_TAG);
  node.value = '{"format":"image-puzzle/build-config","version":1,"kind":"swap","preset":"easy","seed":1}';
  node.addEventListener('configurator-change', (event) => {
    console.log(event.detail.valid);
  });
  return controller.value ?? (node.value === null ? null : buildConfigDocument(parseBuildConfig(node.value)));
}
