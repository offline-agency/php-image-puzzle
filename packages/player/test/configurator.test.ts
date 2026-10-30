import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import { CONFIGURATOR_TAG, createConfigurator, defineImagePuzzleConfigurator } from '../src/index.js';
import type { Configurator, ConfiguratorChange, ImagePuzzleConfiguratorElement } from '../src/index.js';

const FORMAT = 'image-puzzle/build-config';

function mount(options: Parameters<typeof createConfigurator>[1] = {}): {
  configurator: Configurator;
  shadow: ShadowRoot;
  changes: ConfiguratorChange[];
} {
  const target = document.createElement('div');
  document.body.append(target);
  const changes: ConfiguratorChange[] = [];
  const configurator = createConfigurator(target, { ...options, onChange: (change) => changes.push(change) });
  const shadow = configurator.element.shadowRoot;
  if (shadow === null) throw new Error('no shadow root');
  return { configurator, shadow, changes };
}

function query<T extends Element>(shadow: ShadowRoot, type: abstract new (...args: never[]) => T, selector: string): T {
  const node = shadow.querySelector(selector);
  if (!(node instanceof type)) throw new Error(`missing ${selector}`);
  return node;
}

function choose(select: HTMLSelectElement, value: string): void {
  select.value = value;
  select.dispatchEvent(new Event('change'));
}

function type(input: HTMLInputElement, value: string): void {
  input.value = value;
  input.dispatchEvent(new Event('input'));
}

/** jsdom does not decode images: fake a loaded picture of the given natural size. */
function loadImage(shadow: ShadowRoot, width: number, height: number): void {
  const image = query(shadow, HTMLImageElement, '.stage img');
  Object.defineProperty(image, 'naturalWidth', { configurable: true, value: width });
  Object.defineProperty(image, 'naturalHeight', { configurable: true, value: height });
  image.dispatchEvent(new Event('load'));
}

afterEach(() => {
  document.body.replaceChildren();
});

describe('createConfigurator', () => {
  it('starts from a valid default configuration of the first offered mode', () => {
    const { configurator, shadow } = mount({ kinds: ['jigsaw', 'swap'] });
    expect(configurator.value).toEqual({ format: FORMAT, version: 1, kind: 'jigsaw', preset: 'medium', seed: 1 });
    expect([...query(shadow, HTMLSelectElement, 'select[name=kind]').options].map((o) => o.value)).toEqual(['jigsaw', 'swap']);
    expect(query(shadow, HTMLElement, 'fieldset[data-section=custom]').hidden).toBe(true);
  });

  it('loads a saved configuration and reports edits as documents', () => {
    const saved = {
      format: FORMAT,
      version: 1,
      kind: 'strips',
      preset: 'custom',
      custom: { count: 6, axis: 'vertical' },
      seed: 9,
      crop: { x: 1, y: 2, width: 30, height: 20 },
    };
    const { configurator, shadow, changes } = mount({ value: saved });
    expect(configurator.value).toEqual(saved);
    expect(query(shadow, HTMLInputElement, 'input[name=count]').value).toBe('6');
    expect(query(shadow, HTMLSelectElement, 'select[name=axis]').value).toBe('vertical');
    expect(query(shadow, HTMLInputElement, 'input[name=crop-width]').value).toBe('30');

    type(query(shadow, HTMLInputElement, 'input[name=count]'), '7');
    const edited = { ...saved, custom: { count: 7, axis: 'vertical' } };
    expect(changes.at(-1)).toEqual({ value: edited, draft: edited, valid: true, error: null });
  });

  it('prefills custom parameters from the previous preset and validates their ranges', () => {
    const { configurator, shadow, changes } = mount({ kinds: ['sliding'] });
    choose(query(shadow, HTMLSelectElement, 'select[name=preset]'), 'custom');
    expect(configurator.value).toMatchObject({ preset: 'custom', custom: { rows: 4, cols: 4, scrambleMoves: 200 } });

    const rows = query(shadow, HTMLInputElement, 'input[name=rows]');
    type(rows, '1');
    expect(changes.at(-1)).toEqual({
      value: null,
      draft: { format: FORMAT, version: 1, kind: 'sliding', preset: 'custom', custom: { rows: 1, cols: 4, scrambleMoves: 200 }, seed: 1 },
      valid: false,
      error: 'Rows must be a whole number from 2 to 16.',
    });
    expect(rows.getAttribute('aria-invalid')).toBe('true');
    expect(query(shadow, HTMLElement, '.error').textContent).toBe('Rows must be a whole number from 2 to 16.');

    type(rows, '');
    expect(configurator.error).toBe('Rows is required.');
    expect(configurator.draft).toMatchObject({ custom: { rows: null } });
    type(rows, '5');
    expect(configurator.valid).toBe(true);
    expect(rows.hasAttribute('aria-invalid')).toBe(false);
  });

  it('switching mode keeps the preset and drops parameters of the old mode', () => {
    const { configurator, shadow } = mount();
    choose(query(shadow, HTMLSelectElement, 'select[name=preset]'), 'hard');
    choose(query(shadow, HTMLSelectElement, 'select[name=kind]'), 'rotation');
    expect(configurator.value).toEqual({ format: FORMAT, version: 1, kind: 'rotation', preset: 'hard', seed: 1 });
    choose(query(shadow, HTMLSelectElement, 'select[name=preset]'), 'custom');
    expect(configurator.value).toMatchObject({ custom: { rows: 6, cols: 6, orientations: [0, 90, 180, 270] } });

    for (const box of shadow.querySelectorAll<HTMLInputElement>('input[name=orientations]')) {
      box.checked = false;
      box.dispatchEvent(new Event('change'));
    }
    expect(configurator.error).toBe('Choose at least one rotation.');
  });

  it('edits the crop with numbers, keeps it inside the image and resets it', () => {
    const { configurator, shadow } = mount({ src: 'https://example.test/picture.png', kinds: ['swap'] });
    loadImage(shadow, 200, 100);
    expect(configurator.imageSize).toEqual({ width: 200, height: 100 });
    expect(query(shadow, HTMLElement, '.summary').textContent).toBe('4 × 4 board, tiles of about 50 × 25 px');

    type(query(shadow, HTMLInputElement, 'input[name=crop-x]'), '20');
    expect(configurator.value).toBeNull();
    expect(configurator.error).toBe('The crop must lie inside the 200 × 100 px image.');
    expect(query(shadow, HTMLInputElement, 'input[name=crop-x]').getAttribute('aria-invalid')).toBe('true');
    type(query(shadow, HTMLInputElement, 'input[name=crop-width]'), '120');
    expect(configurator.value).toMatchObject({ crop: { x: 20, y: 0, width: 120, height: 100 } });
    expect(query(shadow, HTMLElement, '.summary').textContent).toBe('4 × 4 board, tiles of about 30 × 25 px');
    expect(query(shadow, Element, '.frame').getAttribute('aria-label')).toBe('Crop area 120 × 100 px at 20, 0');

    configurator.resetCrop();
    expect(configurator.value).not.toHaveProperty('crop');
  });

  it('moves and resizes the crop frame with the keyboard', () => {
    const { configurator, shadow } = mount({ kinds: ['swap'], src: 'https://example.test/p.png' });
    loadImage(shadow, 500, 200);
    const frame = query(shadow, Element, '.frame');
    frame.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', shiftKey: true, bubbles: true }));
    expect(configurator.value).toMatchObject({ crop: { x: 0, y: 0, width: 496, height: 200 } });
    frame.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    frame.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    expect(configurator.value).toMatchObject({ crop: { x: 4, y: 0, width: 496, height: 200 } });
  });

  it('rejects a crop too small for the board', () => {
    const { configurator, shadow } = mount({ kinds: ['jigsaw'], src: 'https://example.test/p.png' });
    loadImage(shadow, 300, 300);
    type(query(shadow, HTMLInputElement, 'input[name=crop-width]'), '5');
    expect(configurator.error).toBe('The crop is too small for this board.');
  });

  it('draws a new seed within the mode range', () => {
    const { configurator, shadow } = mount({ kinds: ['jigsaw', 'swap'], random: () => 1 - 2 ** -53 });
    query(shadow, HTMLButtonElement, 'button[data-action=random-seed]').click();
    expect(configurator.value?.seed).toBe(2147483647);
    choose(query(shadow, HTMLSelectElement, 'select[name=kind]'), 'swap');
    configurator.randomizeSeed();
    expect(configurator.value?.seed).toBe(4294967295);
    type(query(shadow, HTMLInputElement, 'input[name=seed]'), '-3');
    expect(configurator.error).toBe('Seed must be a whole number from 0 to 4294967295.');
  });

  it('reports an invalid saved configuration and falls back to defaults', () => {
    const { configurator, shadow } = mount({ value: { format: FORMAT, version: 2, kind: 'swap', preset: 'easy', seed: 0 } });
    expect(configurator.value).toBeNull();
    expect(configurator.error).toBe('Unsupported build configuration version 2; this library reads version 1.');
    expect(query(shadow, HTMLElement, '.error').textContent).toContain('version 2');
  });

  it('disables every control and ignores actions', () => {
    const { configurator, shadow } = mount({ disabled: true, random: () => 0.5 });
    for (const control of shadow.querySelectorAll<HTMLInputElement>('input, select, button')) {
      expect(control.disabled, control.name).toBe(true);
    }
    configurator.randomizeSeed();
    expect(configurator.value?.seed).toBe(1);
    configurator.setDisabled(false);
    expect(query(shadow, HTMLSelectElement, 'select[name=kind]').disabled).toBe(false);
  });

  it('translates labels and messages', () => {
    const { configurator, shadow } = mount({ locale: 'it' });
    expect(query(shadow, HTMLElement, '.root').getAttribute('aria-label')).toBe('Configurazione del puzzle');
    expect(query(shadow, HTMLSelectElement, 'select[name=preset]').selectedOptions[0]?.textContent).toBe('Media');
    configurator.setLocale('en');
    expect(query(shadow, HTMLSelectElement, 'select[name=preset]').selectedOptions[0]?.textContent).toBe('Medium');
  });

  it('builds text-only DOM even from hostile labels', () => {
    const { shadow } = mount({ messages: { kind: '<img src=x onerror=alert(1)>' } });
    expect(shadow.querySelector('img[src=x]')).toBeNull();
    expect(shadow.textContent).toContain('<img src=x onerror=alert(1)>');
  });
});

describe('<image-puzzle-configurator>', () => {
  beforeAll(() => {
    defineImagePuzzleConfigurator();
  });

  it('reads attributes, reflects properties and emits composed change events', () => {
    const element = document.createElement(CONFIGURATOR_TAG);
    element.setAttribute('kinds', 'swap jigsaw bogus');
    element.setAttribute('value', JSON.stringify({ format: FORMAT, version: 1, kind: 'jigsaw', preset: 'easy', seed: 4 }));
    document.body.append(element);
    expect(element.kinds).toEqual(['swap', 'jigsaw']);
    expect(element.value).toEqual({ format: FORMAT, version: 1, kind: 'jigsaw', preset: 'easy', seed: 4 });

    const events: CustomEvent<ConfiguratorChange>[] = [];
    document.body.addEventListener('configurator-change', (event) => events.push(event));
    const select = element.shadowRoot?.querySelector<HTMLSelectElement>('select[name=preset]');
    if (!select) throw new Error('no preset select');
    choose(select, 'hard');
    expect(events).toHaveLength(1);
    expect(events[0]?.composed).toBe(true);
    expect(events[0]?.detail.value).toMatchObject({ preset: 'hard' });

    element.disabled = true;
    expect(select.disabled).toBe(true);
    element.value = '{"not": "json config"}';
    expect(element.valid).toBe(false);
    expect(element.error).toBe('config has unknown field(s): not.');
  });

  it('keeps a value set before the element was upgraded', () => {
    const element = document.createElement('div');
    element.innerHTML = '<image-puzzle-configurator-late></image-puzzle-configurator-late>';
    const late = element.firstElementChild as HTMLElement & { value?: unknown };
    late.value = { format: FORMAT, version: 1, kind: 'swap', preset: 'hard', seed: 2 };
    document.body.append(element);
    defineImagePuzzleConfigurator('image-puzzle-configurator-late');
    expect((late as ImagePuzzleConfiguratorElement).value).toMatchObject({ kind: 'swap', preset: 'hard' });
  });

  it('follows a theme class on <html> and stops when it leaves the document', async () => {
    const element = document.createElement(CONFIGURATOR_TAG);
    element.setAttribute('theme-class', 'dark');
    document.body.append(element);
    expect(element.getAttribute('data-theme')).toBe('light');

    document.documentElement.classList.add('dark');
    await Promise.resolve();
    expect(element.getAttribute('data-theme')).toBe('dark');

    element.remove();
    await Promise.resolve();
    expect(element.hasAttribute('data-theme')).toBe(false);
    document.documentElement.classList.remove('dark');
    await Promise.resolve();
    expect(element.hasAttribute('data-theme')).toBe(false);

    document.body.append(element);
    expect(element.getAttribute('data-theme')).toBe('light');
  });
});
