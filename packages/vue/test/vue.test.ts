import { createApp, defineComponent, h, nextTick, ref, shallowRef } from 'vue';
import type { Component } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { compile } from '@vue/compiler-dom';
import { defineImagePuzzlePlayer } from '@offline-agency/image-puzzle-player';
import type { ImagePuzzlePlayerElement, PlayerEventMap, PlayerHandlers } from '@offline-agency/image-puzzle-player';
import { describeAdapterContract } from '../../player/test/adapter-contract.js';
import type { ContractProps, Mounted } from '../../player/test/adapter-contract.js';
import { definitionDocument, settle } from '../../player/test/support.js';
import { ImagePuzzlePlayer, useImagePuzzlePlayer } from '../src/index.js';
import type { ImagePuzzlePlayerHandle } from '../src/index.js';

function listeners(handlers: PlayerHandlers): Record<string, unknown> {
  return {
    onReady: handlers.ready,
    onMove: handlers.move,
    onStateChange: handlers['state-change'],
    onProgress: handlers.progress,
    onComplete: handlers.complete,
    onError: handlers.error,
  };
}

async function mountVue(container: HTMLElement, props: ContractProps, handlers: PlayerHandlers): Promise<Mounted> {
  const state = shallowRef<{ props: ContractProps; handlers: PlayerHandlers }>({ props, handlers });
  let handle: ImagePuzzlePlayerHandle | null = null;
  const app = createApp({
    render: () =>
      // The contract passes definitions as `unknown`; the component checks them at runtime.
      h(ImagePuzzlePlayer as Component, {
        ...state.value.props,
        ...listeners(state.value.handlers),
        ref: (instance: unknown) => {
          handle = instance as ImagePuzzlePlayerHandle | null;
        },
      }),
  });
  app.mount(container);
  await settle();
  let unmounted = false;
  return {
    player() {
      const player = handle?.player ?? null;
      if (unmounted || player === null) throw new Error('unmounted');
      return player;
    },
    async update(next, nextHandlers) {
      state.value = { props: next, handlers: nextHandlers ?? state.value.handlers };
      await nextTick();
      await settle();
    },
    async unmount() {
      if (unmounted) return;
      unmounted = true;
      app.unmount();
      await settle();
    },
  };
}

describeAdapterContract('Vue <ImagePuzzlePlayer>', mountVue);

describe('Vue adapter specifics', () => {
  afterEach(() => {
    document.body.replaceChildren();
  });

  it('exposes load, reset and state operations through a template ref', async () => {
    const container = document.createElement('div');
    document.body.append(container);
    const handle = ref<ImagePuzzlePlayerHandle | null>(null);
    const app = createApp({ render: () => h(ImagePuzzlePlayer, { ref: handle }) });
    app.mount(container);
    await nextTick();
    const api = handle.value;
    if (api === null) throw new Error('no handle');
    await api.load(definitionDocument('sliding'));
    const player = api.player;
    if (player === null) throw new Error('no player');
    player.dispatchAction(player.engine.legalActions()[0]);
    const saved = api.exportState();
    api.reset();
    expect(player.snapshot().moves).toBe(0);
    api.importState(saved);
    expect(player.snapshot().moves).toBe(1);
    app.unmount();
    expect(api.player).toBeNull();
    expect(() => {
      api.reset();
    }).toThrow(/not mounted/);
  });

  it('remounts with new creation options when the key changes', async () => {
    const container = document.createElement('div');
    document.body.append(container);
    const key = ref('a');
    const controls = ref(true);
    const definition = definitionDocument('swap');
    const handle = ref<ImagePuzzlePlayerHandle | null>(null);
    const app = createApp({ render: () => h(ImagePuzzlePlayer, { key: key.value, definition, controls: controls.value, ref: handle }) });
    app.mount(container);
    await settle();
    const first = handle.value?.player;
    const toolbar = (): boolean | 'until-found' | undefined =>
      container.querySelector('.image-puzzle-player')?.shadowRoot?.querySelector<HTMLElement>('.toolbar')?.hidden;
    controls.value = false;
    await nextTick();
    expect(handle.value?.player).toBe(first);
    expect(toolbar()).toBe(false);
    key.value = 'b';
    await nextTick();
    expect(handle.value?.player).not.toBe(first);
    expect(first?.engine.status).toBe('destroyed');
    expect(toolbar()).toBe(true);
    app.unmount();
  });

  it('validates emitted payloads and forwards them unchanged', async () => {
    const container = document.createElement('div');
    document.body.append(container);
    const onReady = vi.fn();
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    try {
      const app = createApp({ render: () => h(ImagePuzzlePlayer, { definition: definitionDocument('swap'), onReady }) });
      app.mount(container);
      await settle();
      expect(onReady).toHaveBeenCalledTimes(1);
      expect(onReady.mock.calls[0]?.[0]).toMatchObject({ kind: 'swap' });
      expect(warn).not.toHaveBeenCalled();
      app.unmount();
    } finally {
      warn.mockRestore();
    }
  });

  it('compiles <image-puzzle-player> as a custom element with the documented isCustomElement option', () => {
    const template = '<image-puzzle-player :definition="definition" locale="it" @puzzle-ready="onReady" />';
    const isCustomElement = (tag: string): boolean => tag.startsWith('image-puzzle-');
    expect(compile(template).code).toContain('resolveComponent("image-puzzle-player")');
    const { code } = compile(template, { isCustomElement });
    expect(code).not.toContain('resolveComponent');
    expect(code).toContain('onPuzzleReady: onReady');
  });

  it('drives the Web Component directly: objects become properties and puzzle-* events reach listeners', async () => {
    defineImagePuzzlePlayer();
    const container = document.createElement('div');
    document.body.append(container);
    const definition = definitionDocument('swap');
    const onReady = vi.fn();
    const onMove = vi.fn();
    // The props the template above compiles to.
    const app = createApp({
      render: () => h('image-puzzle-player', { definition, locale: 'it', onPuzzleReady: onReady, onPuzzleMove: onMove }),
    });
    app.mount(container);
    const element = container.querySelector('image-puzzle-player') as ImagePuzzlePlayerElement;
    await vi.waitFor(() => {
      expect(onReady).toHaveBeenCalledTimes(1);
    });
    expect(element.definition).toBe(definition);
    expect(element.hasAttribute('definition')).toBe(false);
    expect(element.getAttribute('locale')).toBe('it');
    const ready = onReady.mock.calls[0]?.[0] as CustomEvent<PlayerEventMap['ready']>;
    expect(ready.detail.definitionId).toBe(String(definition['id']));
    element.dispatchAction(element.engine.legalActions()[0]);
    expect(onMove).toHaveBeenCalledTimes(1);
    app.unmount();
  });

  it('works as a composable on any element', async () => {
    const container = document.createElement('div');
    document.body.append(container);
    const onMove = vi.fn();
    const definition = shallowRef<unknown>(definitionDocument('rotation'));
    const exposed: { value: ReturnType<typeof useImagePuzzlePlayer> | null } = { value: null };
    const Host = defineComponent({
      setup() {
        const target = ref<HTMLElement | null>(null);
        exposed.value = useImagePuzzlePlayer(target, () => ({ definition: definition.value }), { move: onMove });
        return () => h('section', { ref: target });
      },
    });
    const app = createApp(Host);
    app.mount(container);
    await settle();
    const player = exposed.value?.player.value;
    if (player === null || player === undefined) throw new Error('no player');
    expect(container.querySelector('section > .image-puzzle-player')).not.toBeNull();
    player.dispatchAction(player.engine.legalActions()[0]);
    expect(onMove).toHaveBeenCalledTimes(1);
    definition.value = definitionDocument('strips');
    await nextTick();
    await settle();
    expect(player.engine.definition?.kind).toBe('strips');
    app.unmount();
    expect(exposed.value?.player.value).toBeNull();
  });
});
