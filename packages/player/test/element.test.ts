import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { DEFAULT_TAG, createPlayer, defineImagePuzzlePlayer } from '../src/index.js';
import type { ImagePuzzlePlayerElement, PlayerEventType } from '../src/index.js';
import { definitionDocument, fakeFetch, manualClock, memoryStorage, settle } from './support.js';

const EVENTS = ['ready', 'move', 'state-change', 'progress', 'complete', 'error'] as const;

function create(attributes: Record<string, string> = {}): ImagePuzzlePlayerElement {
  const element = document.createElement(DEFAULT_TAG);
  for (const [name, value] of Object.entries(attributes)) element.setAttribute(name, value);
  return element;
}

function capture(target: EventTarget): { type: string; detail: unknown; composed: boolean; bubbles: boolean }[] {
  const events: { type: string; detail: unknown; composed: boolean; bubbles: boolean }[] = [];
  for (const type of EVENTS) {
    target.addEventListener(`puzzle-${type}`, (event) => {
      const custom = event as CustomEvent;
      events.push({ type, detail: custom.detail, composed: custom.composed, bubbles: custom.bubbles });
    });
  }
  return events;
}

beforeAll(() => {
  defineImagePuzzlePlayer();
});
afterEach(() => {
  for (const element of document.querySelectorAll(DEFAULT_TAG)) element.dispose();
  document.body.replaceChildren();
});

describe('<image-puzzle-player>', () => {
  it('registers idempotently, also through the side-effect entry', async () => {
    const first = defineImagePuzzlePlayer();
    expect(defineImagePuzzlePlayer()).toBe(first);
    await import('../src/define.js');
    await import('../src/define.js');
    expect(customElements.get(DEFAULT_TAG)).toBe(first);
    expect(document.createElement(DEFAULT_TAG)).toBeInstanceOf(first as CustomElementConstructor);
  });

  it('loads its src attribute on connection and dispatches composed, bubbling events', async () => {
    const fetch = fakeFetch({ 'sliding.json': definitionDocument('sliding') });
    const element = create({ src: 'sliding.json' });
    element.options = { fetch };
    const events = capture(document);
    document.body.append(element);
    await settle();
    expect(events.map((event) => event.type)).toEqual(['state-change', 'progress', 'ready']);
    expect(events.every((event) => event.bubbles && event.composed)).toBe(true);
    expect(element.shadowRoot?.querySelectorAll('button.tile')).toHaveLength(8);
  });

  it('crosses an enclosing shadow boundary', async () => {
    const outer = document.createElement('div');
    const root = outer.attachShadow({ mode: 'open' });
    const element = create();
    const events = capture(document);
    element.definition = definitionDocument('swap');
    root.append(element);
    document.body.append(outer);
    await settle();
    expect(events.find((event) => event.type === 'ready')).toBeDefined();
  });

  it('prefers the definition property over src and falls back when it is cleared', async () => {
    const fetch = fakeFetch({ 'sliding.json': definitionDocument('sliding') });
    const element = create({ src: 'sliding.json' });
    element.options = { fetch };
    element.definition = definitionDocument('swap');
    document.body.append(element);
    await settle();
    expect(element.engine.definition?.kind).toBe('swap');
    expect(fetch.calls).toHaveLength(0);

    element.setAttribute('src', 'other.json'); // definition still wins
    await settle();
    expect(fetch.calls).toHaveLength(0);

    element.src = 'sliding.json';
    element.definition = null;
    await settle();
    expect(fetch.calls).toHaveLength(1);
    expect(element.engine.definition?.kind).toBe('sliding');
  });

  it('batches changes made in one task into a single load', async () => {
    const element = create();
    document.body.append(element);
    const ready = vi.fn();
    element.addEventListener('puzzle-ready', ready);
    element.definition = definitionDocument('swap');
    element.definition = definitionDocument('rotation');
    element.state = undefined;
    await settle();
    expect(ready).toHaveBeenCalledTimes(1);
    expect(element.engine.definition?.kind).toBe('rotation');
  });

  it('applies the state property to the next load only', async () => {
    const definition = definitionDocument('swap');
    const source = createPlayer(document.createElement('div'));
    await source.load(definition);
    source.dispatchAction(source.engine.legalActions()[0]);
    const saved = source.exportState();
    source.destroy();

    const element = create();
    element.definition = definition;
    element.state = saved;
    document.body.append(element);
    await settle();
    expect(element.snapshot().moves).toBe(1);
    expect(element.state).toBeUndefined();
    element.reset();
    expect(element.snapshot().moves).toBe(0);
  });

  it('honours properties assigned before the element was upgraded', async () => {
    const tag = 'image-puzzle-player-late';
    const element = document.createElement(tag) as HTMLElement & { definition?: unknown };
    element.definition = definitionDocument('strips');
    document.body.append(element);
    defineImagePuzzlePlayer(tag);
    await settle();
    expect((element as unknown as ImagePuzzlePlayerElement).engine.definition?.kind).toBe('strips');
  });

  it('keeps one engine and its state across DOM moves', async () => {
    const element = create();
    element.definition = definitionDocument('swap');
    const a = document.createElement('section');
    const b = document.createElement('section');
    document.body.append(a, b);
    a.append(element);
    await settle();
    const engine = element.engine;
    element.dispatchAction(engine.legalActions()[0]);
    b.append(element);
    await settle();
    expect(element.engine).toBe(engine);
    expect(element.snapshot().moves).toBe(1);
    expect(element.engine.status).toBe('running');
  });

  it('suspends while detached, keeps state, and resumes on reconnection', async () => {
    const clock = manualClock();
    const storage = memoryStorage();
    const element = create();
    element.options = { clock: clock.now, persist: { storage }, pauseWhenHidden: false };
    element.definition = definitionDocument('swap');
    document.body.append(element);
    await settle();
    element.dispatchAction(element.engine.legalActions()[0]);
    clock.advance(1000);
    element.remove();
    await settle();
    expect(element.engine.status).toBe('paused');
    expect(storage.data.size).toBe(1); // flushed on suspend
    clock.advance(60_000);
    document.body.append(element);
    await settle();
    expect(element.engine.status).toBe('running');
    expect(element.snapshot().elapsedMs).toBe(1000);
    expect(element.snapshot().moves).toBe(1);
  });

  it('dispose() releases the player; a later connection starts a new one', async () => {
    const element = create();
    element.definition = definitionDocument('swap');
    document.body.append(element);
    await settle();
    const engine = element.engine;
    element.dispose();
    expect(engine.status).toBe('destroyed');
    expect(element.shadowRoot?.childNodes).toHaveLength(0);
    element.remove();
    document.body.append(element);
    await settle();
    expect(element.engine).not.toBe(engine);
    expect(element.engine.definition?.kind).toBe('swap');
  });

  it('reports a failed fetch as a puzzle-error event and an error view', async () => {
    const element = create({ src: 'missing.json' });
    element.options = { fetch: fakeFetch({}) };
    const events = capture(element);
    document.body.append(element);
    await settle();
    expect(events).toEqual([
      { type: 'error', detail: { code: 'load', message: expect.stringContaining('HTTP 404') as unknown }, composed: true, bubbles: true },
    ]);
    expect(element.player.view).toBe('error');
    expect(element.getAttribute('data-state')).toBe('error');
  });

  it('updates labels when the locale attribute changes', async () => {
    const element = create({ locale: 'en' });
    element.definition = definitionDocument('swap');
    document.body.append(element);
    await settle();
    const reset = element.shadowRoot?.querySelector('[data-control="reset"]');
    expect(reset?.textContent).toBe('Reset');
    element.locale = 'it';
    expect(reset?.textContent).toBe('Ricomincia');
  });

  it('reads persist, no-controls and motion attributes when the player is created', () => {
    const element = create({ 'no-controls': '', motion: 'reduce' });
    document.body.append(element);
    expect((element.shadowRoot?.querySelector('.toolbar') as HTMLElement).hidden).toBe(true);
    expect(element.getAttribute('data-motion')).toBe('reduce');
  });

  it('exposes the same imperative API and event stream as the plain player', async () => {
    const run = async (
      subscribe: (type: PlayerEventType, push: (detail: unknown) => void) => void,
      api: ImagePuzzlePlayerElement | ReturnType<typeof createPlayer>,
    ) => {
      const log: { type: string; detail: unknown }[] = [];
      for (const type of EVENTS) subscribe(type, (detail) => log.push({ type, detail }));
      await api.load(definitionDocument('rotation'));
      for (let i = 0; i < 40 && api.engine.status !== 'complete'; i++) {
        const action = api.engine.hint();
        if (action !== null) api.dispatchAction(action);
        if (i === 1) {
          api.undo();
          api.redo();
        }
      }
      return log;
    };
    const clockA = manualClock();
    const plain = createPlayer(document.createElement('div'), { clock: clockA.now });
    const plainLog = await run((type, push) => {
      plain.subscribe(type, push);
    }, plain);
    plain.destroy();

    const clockB = manualClock();
    const element = create();
    element.options = { clock: clockB.now };
    document.body.append(element);
    const elementLog = await run((type, push) => {
      element.addEventListener(`puzzle-${type}`, (event) => {
        push((event as CustomEvent).detail);
      });
    }, element);
    expect(elementLog).toEqual(plainLog);
    expect(plainLog.at(-1)?.type).toBe('complete');
  });
});
