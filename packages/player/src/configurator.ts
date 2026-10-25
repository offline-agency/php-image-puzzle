import {
  BUILD_CONFIG_FORMAT,
  BUILD_CONFIG_VERSION,
  BUILD_KINDS,
  MAX_CROP,
  MAX_JIGSAW_SEED,
  MAX_SEED,
  PRESET_NAMES,
  PRESET_SHAPES,
  PuzzleError,
  buildConfigDocument,
  buildShape,
  createBuildConfig,
  parseBuildConfig,
} from '@offline-agency/image-puzzle-engine';
import type { BuildConfig, BuildConfigDocument, BuildKind, CropRect } from '@offline-agency/image-puzzle-engine';
import { resolveConfiguratorMessages } from './configurator-messages.js';
import type { ConfiguratorMessages } from './configurator-messages.js';
import { el } from './dom.js';
import { format } from './messages.js';
import { themeTokens } from './theme.js';

/**
 * Visual editor of a build configuration (docs/schemas/game/v1/build-config.schema.json):
 * mode, difficulty or custom parameters, seed and crop, with a live preview
 * of the crop and the board grid over the source image. It produces the
 * document the PHP DefinitionBuilder consumes; the server re-validates it.
 */

export interface ConfiguratorChange {
  /** The configuration document, or null while the form is invalid. */
  readonly value: BuildConfigDocument | null;
  /** What the form currently holds, valid or not (empty numbers are null), for servers that report their own errors. */
  readonly draft: Readonly<Record<string, unknown>>;
  readonly valid: boolean;
  readonly error: string | null;
}

export interface ConfiguratorOptions {
  /** Source image URL for the preview (crop coordinates are in its natural pixels). */
  readonly src?: string | null;
  /** Initial configuration document; invalid documents fall back to defaults and report the error. */
  readonly value?: unknown;
  /** Modes offered, in order (default: every buildable mode). */
  readonly kinds?: readonly BuildKind[];
  readonly disabled?: boolean;
  readonly locale?: string;
  readonly messages?: Partial<ConfiguratorMessages>;
  /** Random source for "New seed" (tests inject a deterministic one). */
  readonly random?: () => number;
  readonly onChange?: (change: ConfiguratorChange) => void;
}

export interface Configurator {
  readonly element: HTMLElement;
  readonly value: BuildConfigDocument | null;
  /** The form contents as a document, valid or not. */
  readonly draft: Readonly<Record<string, unknown>>;
  readonly valid: boolean;
  readonly error: string | null;
  /** Natural size of the loaded preview image, if any. */
  readonly imageSize: { readonly width: number; readonly height: number } | null;
  setValue(value: unknown): void;
  setSource(src: string | null): void;
  setKinds(kinds: readonly BuildKind[]): void;
  setDisabled(disabled: boolean): void;
  setLocale(locale?: string, messages?: Partial<ConfiguratorMessages>): void;
  randomizeSeed(): void;
  resetCrop(): void;
  destroy(): void;
}

type FieldSpec =
  | { readonly key: string; readonly type: 'int'; readonly min: number; readonly max: number; readonly optional?: boolean }
  | { readonly key: string; readonly type: 'choice'; readonly options: readonly string[] }
  | { readonly key: 'orientations'; readonly type: 'angles' }
  | { readonly key: string; readonly type: 'flag' };

/** Custom parameters per mode, with the ranges the PHP generator accepts (GameFactory, DefinitionBuilder). */
const FIELDS: Readonly<Record<BuildKind, readonly FieldSpec[]>> = {
  sliding: [
    { key: 'rows', type: 'int', min: 2, max: 16 },
    { key: 'cols', type: 'int', min: 2, max: 16 },
    { key: 'scrambleMoves', type: 'int', min: 1, max: 100000 },
  ],
  swap: [
    { key: 'rows', type: 'int', min: 1, max: 16 },
    { key: 'cols', type: 'int', min: 1, max: 16 },
  ],
  rotation: [
    { key: 'rows', type: 'int', min: 1, max: 16 },
    { key: 'cols', type: 'int', min: 1, max: 16 },
    { key: 'orientations', type: 'angles' },
  ],
  strips: [
    { key: 'count', type: 'int', min: 2, max: 128 },
    { key: 'axis', type: 'choice', options: ['horizontal', 'vertical'] },
  ],
  jigsaw: [
    { key: 'rows', type: 'int', min: 1, max: 64 },
    { key: 'cols', type: 'int', min: 1, max: 64 },
    { key: 'topology', type: 'choice', options: ['rect', 'hex', 'triangle'] },
    { key: 'edge', type: 'choice', options: ['jigsaw', 'line', 'arc', 'half_circle', 'sinus', 'no_edge'] },
    { key: 'snap', type: 'int', min: 1, max: 256, optional: true },
  ],
  nonogram: [
    { key: 'rows', type: 'int', min: 2, max: 30 },
    { key: 'cols', type: 'int', min: 2, max: 30 },
    { key: 'threshold', type: 'int', min: 1, max: 255, optional: true },
    { key: 'invert', type: 'flag' },
    { key: 'requireUnique', type: 'flag' },
  ],
};

const ANGLES = [0, 90, 180, 270] as const;

interface Draft {
  kind: BuildKind;
  preset: string;
  custom: Record<string, unknown>;
  seed: number;
  crop: CropRect | null;
}

const styles = `${themeTokens}
:host { display: block; color: var(--ipp-fg); font-family: var(--ipp-font); container-type: inline-size; }
:host([hidden]) { display: none; }
* { box-sizing: border-box; }
.root { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1fr); background: var(--ipp-bg); }
@container (min-width: 44rem) { .root { grid-template-columns: minmax(15rem, 20rem) minmax(0, 1fr); } }
.fields { display: grid; gap: 0.75rem; align-content: start; }
fieldset { margin: 0; padding: 0.5rem 0.75rem 0.75rem; border: 1px solid var(--ipp-border); border-radius: var(--ipp-radius); display: grid; gap: 0.5rem; }
fieldset[hidden] { display: none; }
legend { padding: 0 0.25rem; font-weight: 600; font-size: 0.875rem; }
label { display: grid; gap: 0.25rem; font-size: 0.875rem; }
.pair { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; }
.inline { display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; }
.inline label { display: flex; align-items: center; gap: 0.375rem; }
input, select, button { font: inherit; font-size: 0.875rem; color: var(--ipp-fg); }
input[type='number'], select { min-height: 2.5rem; padding: 0.25rem 0.5rem; border: 1px solid var(--ipp-border); border-radius: var(--ipp-radius); background: var(--ipp-bg); width: 100%; }
input[type='checkbox'] { width: 1.125rem; height: 1.125rem; accent-color: var(--ipp-accent); }
input[aria-invalid='true'], select[aria-invalid='true'] { border-color: var(--ipp-error); }
.row { display: flex; gap: 0.5rem; align-items: end; }
.row > label { flex: 1; }
button { min-height: 2.5rem; padding: 0.25rem 0.75rem; border: 1px solid var(--ipp-border); border-radius: var(--ipp-radius); background: var(--ipp-surface); cursor: pointer; }
button:disabled, input:disabled, select:disabled { opacity: 0.6; cursor: not-allowed; }
:focus-visible { outline: 3px solid var(--ipp-focus); outline-offset: 2px; }
.error { margin: 0; color: var(--ipp-error); font-size: 0.875rem; }
.error:empty { display: none; }
.help, .note, .summary { margin: 0; color: var(--ipp-muted); font-size: 0.8125rem; }
.preview { display: grid; gap: 0.5rem; align-content: start; }
.stage { position: relative; width: 100%; background: var(--ipp-tray); border-radius: var(--ipp-radius); overflow: hidden; touch-action: none; user-select: none; -webkit-user-select: none; }
.stage[hidden], .placeholder[hidden] { display: none; }
.stage img { display: block; width: 100%; height: auto; pointer-events: none; }
.stage svg { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; }
.shade { fill: rgb(0 0 0 / 0.55); fill-rule: evenodd; pointer-events: none; }
.grid line { stroke: #fff; stroke-opacity: 0.85; vector-effect: non-scaling-stroke; stroke-width: 1; pointer-events: none; }
.frame { fill: transparent; stroke: #fff; stroke-width: 2; vector-effect: non-scaling-stroke; cursor: move; }
.frame:focus { outline: none; }
.frame:focus-visible { stroke: var(--ipp-focus); stroke-width: 4; }
.handle { fill: #fff; stroke: #1d232b; stroke-width: 1; vector-effect: non-scaling-stroke; }
.handle[data-corner='nw'], .handle[data-corner='se'] { cursor: nwse-resize; }
.handle[data-corner='ne'], .handle[data-corner='sw'] { cursor: nesw-resize; }
:host([disabled]) .frame, :host([disabled]) .handle, :host([data-disabled]) .frame, :host([data-disabled]) .handle { cursor: default; }
.placeholder { margin: 0; padding: 2rem 1rem; border: 1px dashed var(--ipp-border); border-radius: var(--ipp-radius); color: var(--ipp-muted); text-align: center; }
.sr-only { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
@media (forced-colors: active) {
  .frame, .grid line { stroke: CanvasText; }
  .handle { fill: Canvas; stroke: CanvasText; }
  .frame:focus-visible { stroke: Highlight; }
}
`;

const SVG = 'http://www.w3.org/2000/svg';
let instances = 0;

function defaults(kind: BuildKind): Draft {
  return { kind, preset: 'medium', custom: {}, seed: 1, crop: null };
}

function customFromPreset(kind: BuildKind, preset: string): Record<string, unknown> {
  const shape = (PRESET_SHAPES[kind] as Readonly<Record<string, Readonly<Record<string, unknown>>>>)[preset] ?? PRESET_SHAPES[kind].medium;
  const custom: Record<string, unknown> = {};
  for (const field of FIELDS[kind]) {
    const value = shape[field.key];
    if (value !== undefined) custom[field.key] = Array.isArray(value) ? [...(value as unknown[])] : value;
  }
  if (kind === 'jigsaw') custom['topology'] ??= 'rect';
  if (kind === 'rotation') custom['orientations'] ??= [0, 90, 180, 270];
  return custom;
}

export function createConfigurator(target: HTMLElement | ShadowRoot, options: ConfiguratorOptions = {}): Configurator {
  const doc = target.ownerDocument;
  const id = `ipc${++instances}`;
  let shadow: ShadowRoot;
  let host: HTMLElement;
  const ownsHost = !(target instanceof ShadowRoot);
  if (target instanceof ShadowRoot) {
    shadow = target;
    host = target.host as HTMLElement;
  } else {
    host = el(doc, 'div', { class: 'image-puzzle-configurator' });
    target.append(host);
    shadow = host.attachShadow({ mode: 'open' });
  }

  let messages = resolveConfiguratorMessages(options.locale, options.messages);
  let kinds: readonly BuildKind[] = options.kinds !== undefined && options.kinds.length > 0 ? [...options.kinds] : [...BUILD_KINDS];
  let disabled = options.disabled ?? false;
  const random = options.random ?? Math.random;
  let draft: Draft = defaults(kinds[0] ?? 'jigsaw');
  let current: BuildConfigDocument | null = null;
  let error: string | null = null;
  let invalidField: string | null = null;
  let imageSize: { width: number; height: number } | null = null;
  let imageFailed = false;
  let src: string | null = null;
  let destroyed = false;

  // --- DOM -------------------------------------------------------------------
  const root = el(doc, 'div', { class: 'root', part: 'root', role: 'group' });
  const fields = el(doc, 'div', { class: 'fields', part: 'fields' });

  const kindLabel = el(doc, 'label');
  const kindText = el(doc, 'span');
  const kindSelect = el(doc, 'select', { name: 'kind', 'data-field': 'kind' });
  kindLabel.append(kindText, kindSelect);

  const presetLabel = el(doc, 'label');
  const presetText = el(doc, 'span');
  const presetSelect = el(doc, 'select', { name: 'preset', 'data-field': 'preset' });
  presetLabel.append(presetText, presetSelect);

  const customSet = el(doc, 'fieldset', { 'data-section': 'custom' });
  const customLegend = el(doc, 'legend');
  const customBody = el(doc, 'div', { class: 'pair' });
  customSet.append(customLegend, customBody);

  const seedRow = el(doc, 'div', { class: 'row' });
  const seedLabel = el(doc, 'label');
  const seedText = el(doc, 'span');
  const seedInput = el(doc, 'input', { type: 'number', name: 'seed', min: '0', step: '1', inputmode: 'numeric', 'data-field': 'seed' });
  seedLabel.append(seedText, seedInput);
  const seedButton = el(doc, 'button', { type: 'button', 'data-action': 'random-seed' });
  seedRow.append(seedLabel, seedButton);

  const cropSet = el(doc, 'fieldset', { 'data-section': 'crop' });
  const cropLegend = el(doc, 'legend');
  const cropGrid = el(doc, 'div', { class: 'pair' });
  const cropInputs = {} as Record<keyof CropRect, HTMLInputElement>;
  const cropTexts = {} as Record<keyof CropRect, HTMLSpanElement>;
  for (const key of ['x', 'y', 'width', 'height'] as const) {
    const label = el(doc, 'label');
    cropTexts[key] = el(doc, 'span');
    cropInputs[key] = el(doc, 'input', {
      type: 'number',
      name: `crop-${key}`,
      min: key === 'x' || key === 'y' ? '0' : '1',
      max: String(MAX_CROP),
      step: '1',
      inputmode: 'numeric',
      'data-field': `crop.${key}`,
    });
    label.append(cropTexts[key], cropInputs[key]);
    cropGrid.append(label);
  }
  const cropReset = el(doc, 'button', { type: 'button', 'data-action': 'reset-crop' });
  cropSet.append(cropLegend, cropGrid, cropReset);

  const errorText = el(doc, 'p', { class: 'error', part: 'error', role: 'alert', id: `${id}-error` });
  fields.append(kindLabel, presetLabel, customSet, seedRow, cropSet, errorText);

  const preview = el(doc, 'div', { class: 'preview', part: 'preview' });
  const previewHeading = el(doc, 'span', { class: 'sr-only' });
  const placeholder = el(doc, 'p', { class: 'placeholder' });
  const stage = el(doc, 'div', { class: 'stage', part: 'stage' });
  const image = el(doc, 'img', { alt: '', draggable: 'false' });
  const svg = doc.createElementNS(SVG, 'svg');
  const shade = doc.createElementNS(SVG, 'path');
  shade.setAttribute('class', 'shade');
  const grid = doc.createElementNS(SVG, 'g');
  grid.setAttribute('class', 'grid');
  const frame = doc.createElementNS(SVG, 'rect');
  frame.setAttribute('class', 'frame');
  frame.setAttribute('role', 'button');
  frame.setAttribute('aria-describedby', `${id}-help`);
  const handles = (['nw', 'ne', 'sw', 'se'] as const).map((corner) => {
    const handle = doc.createElementNS(SVG, 'rect');
    handle.setAttribute('class', 'handle');
    handle.setAttribute('data-corner', corner);
    return handle;
  });
  svg.append(shade, grid, frame, ...handles);
  stage.append(image, svg);
  const help = el(doc, 'p', { class: 'help', id: `${id}-help` });
  const summary = el(doc, 'p', { class: 'summary', part: 'summary', 'aria-live': 'polite' });
  const note = el(doc, 'p', { class: 'note' });
  preview.append(previewHeading, placeholder, stage, summary, note, help);

  root.append(fields, preview);
  shadow.append(el(doc, 'style', {}, styles), root);

  // --- helpers ---------------------------------------------------------------
  const fillSelect = (select: HTMLSelectElement, values: readonly string[], label: (value: string) => string, selected: string): void => {
    select.replaceChildren(...values.map((value) => el(doc, 'option', { value }, label(value))));
    select.value = values.includes(selected) ? selected : (values[0] ?? '');
  };

  const fieldLabel = (key: string): string => messages.fields[key] ?? key;

  const intOf = (input: HTMLInputElement): number | null => {
    const raw = input.value.trim();
    if (raw === '') return null;
    const value = Number(raw);
    return Number.isFinite(value) ? value : Number.NaN;
  };

  function renderCustom(): void {
    customBody.replaceChildren();
    for (const field of FIELDS[draft.kind]) {
      if (field.type === 'angles') {
        const set = el(doc, 'fieldset', { 'data-field': 'orientations', style: 'grid-column: 1 / -1' });
        set.append(el(doc, 'legend', {}, fieldLabel('orientations')));
        const row = el(doc, 'div', { class: 'inline' });
        const chosen = Array.isArray(draft.custom['orientations']) ? (draft.custom['orientations'] as unknown[]) : [];
        for (const angle of ANGLES) {
          const label = el(doc, 'label');
          const box = el(doc, 'input', { type: 'checkbox', value: String(angle), name: 'orientations' });
          box.checked = chosen.includes(angle);
          box.disabled = disabled;
          box.addEventListener('change', () => {
            const angles = ANGLES.filter(
              (a) => customBody.querySelector<HTMLInputElement>(`input[name='orientations'][value='${a}']`)?.checked ?? false,
            );
            draft.custom['orientations'] = angles;
            update();
          });
          label.append(box, `${angle}°`);
          row.append(label);
        }
        set.append(row);
        customBody.append(set);
        continue;
      }
      if (field.type === 'flag') {
        // Unchecked flags are left out: the generator defaults them to false.
        const label = el(doc, 'label', { class: 'inline', style: 'grid-column: 1 / -1' });
        const box = el(doc, 'input', { type: 'checkbox', name: field.key, 'data-field': `custom.${field.key}` });
        box.checked = draft.custom[field.key] === true;
        box.disabled = disabled;
        box.addEventListener('change', () => {
          if (box.checked) draft.custom[field.key] = true;
          else Reflect.deleteProperty(draft.custom, field.key);
          update();
        });
        label.append(box, fieldLabel(field.key));
        customBody.append(label);
        continue;
      }
      const label = el(doc, 'label');
      const text = el(
        doc,
        'span',
        {},
        field.type === 'int' && field.optional === true ? `${fieldLabel(field.key)} ${messages.optional}` : fieldLabel(field.key),
      );
      if (field.type === 'choice') {
        const select = el(doc, 'select', { name: field.key, 'data-field': `custom.${field.key}` });
        fillSelect(
          select,
          field.options,
          (value) => messages.choices[value] ?? value,
          typeof draft.custom[field.key] === 'string' ? (draft.custom[field.key] as string) : (field.options[0] ?? ''),
        );
        draft.custom[field.key] = select.value;
        select.disabled = disabled;
        select.addEventListener('change', () => {
          draft.custom[field.key] = select.value;
          update();
        });
        label.append(text, select);
      } else {
        const input = el(doc, 'input', {
          type: 'number',
          name: field.key,
          min: String(field.min),
          max: String(field.max),
          step: '1',
          inputmode: 'numeric',
          'data-field': `custom.${field.key}`,
        });
        const value = draft.custom[field.key];
        input.value = typeof value === 'number' ? String(value) : '';
        input.disabled = disabled;
        input.addEventListener('input', () => {
          const parsed = intOf(input);
          if (parsed === null && field.optional === true) Reflect.deleteProperty(draft.custom, field.key);
          else draft.custom[field.key] = parsed;
          update();
        });
        label.append(text, input);
      }
      customBody.append(label);
    }
  }

  function renderStatic(): void {
    root.setAttribute('aria-label', messages.label);
    kindText.textContent = messages.kind;
    presetText.textContent = messages.preset;
    customLegend.textContent = messages.parameters;
    seedText.textContent = messages.seed;
    seedButton.textContent = messages.randomSeed;
    cropLegend.textContent = messages.crop;
    cropTexts.x.textContent = messages.cropX;
    cropTexts.y.textContent = messages.cropY;
    cropTexts.width.textContent = messages.cropWidth;
    cropTexts.height.textContent = messages.cropHeight;
    cropReset.textContent = messages.resetCrop;
    previewHeading.textContent = messages.preview;
    help.textContent = messages.cropHelp;
    fillSelect(kindSelect, kinds, (kind) => messages.kinds[kind] ?? kind, draft.kind);
    fillSelect(presetSelect, [...PRESET_NAMES, 'custom'], (name) => messages.presets[name] ?? name, draft.preset);
    renderCustom();
  }

  function syncInputs(): void {
    kindSelect.value = draft.kind;
    presetSelect.value = draft.preset;
    customSet.hidden = draft.preset !== 'custom';
    seedInput.max = String(draft.kind === 'jigsaw' ? MAX_JIGSAW_SEED : MAX_SEED);
    if (intOf(seedInput) !== draft.seed) seedInput.value = Number.isNaN(draft.seed) ? seedInput.value : String(draft.seed);
    for (const key of ['x', 'y', 'width', 'height'] as const) {
      const value = draft.crop?.[key];
      if (value === undefined) {
        cropInputs[key].value = '';
      } else if (intOf(cropInputs[key]) !== value) {
        cropInputs[key].value = String(value);
      }
    }
    for (const control of [kindSelect, presetSelect, seedInput, seedButton, cropReset, ...Object.values(cropInputs)]) {
      control.disabled = disabled;
    }
    for (const control of customBody.querySelectorAll<HTMLInputElement | HTMLSelectElement>('input, select')) {
      control.disabled = disabled;
    }
    cropReset.disabled = disabled || draft.crop === null;
    frame.setAttribute('tabindex', disabled || imageSize === null ? '-1' : '0');
    frame.setAttribute('aria-disabled', String(disabled));
  }

  /** Client-side checks with the generator's ranges; the PHP builder stays authoritative. */
  function validate(): { config: BuildConfig | null; message: string | null; field: string | null } {
    if (draft.preset === 'custom') {
      for (const field of FIELDS[draft.kind]) {
        const value = draft.custom[field.key];
        if (field.type === 'int') {
          if (value === undefined && field.optional === true) continue;
          if (value === null || value === undefined) {
            return { config: null, message: format(messages.required, { field: fieldLabel(field.key) }), field: `custom.${field.key}` };
          }
          if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < field.min || value > field.max) {
            return {
              config: null,
              message: format(messages.range, { field: fieldLabel(field.key), min: field.min, max: field.max }),
              field: `custom.${field.key}`,
            };
          }
        } else if (field.type === 'angles' && (!Array.isArray(value) || value.length === 0)) {
          return { config: null, message: messages.orientationsRequired, field: 'orientations' };
        }
      }
    }
    const seedMax = draft.kind === 'jigsaw' ? MAX_JIGSAW_SEED : MAX_SEED;
    if (!Number.isSafeInteger(draft.seed) || draft.seed < 0 || draft.seed > seedMax) {
      return { config: null, message: format(messages.range, { field: messages.seed, min: 0, max: seedMax }), field: 'seed' };
    }
    const crop = draft.crop;
    if (crop !== null) {
      for (const key of ['x', 'y', 'width', 'height'] as const) {
        const min = key === 'x' || key === 'y' ? 0 : 1;
        if (!Number.isSafeInteger(crop[key]) || crop[key] < min || crop[key] > MAX_CROP) {
          const label = { x: messages.cropX, y: messages.cropY, width: messages.cropWidth, height: messages.cropHeight }[key];
          return { config: null, message: format(messages.range, { field: label, min, max: MAX_CROP }), field: `crop.${key}` };
        }
      }
      if (imageSize !== null && (crop.x + crop.width > imageSize.width || crop.y + crop.height > imageSize.height)) {
        return { config: null, message: format(messages.cropOutside, imageSize), field: 'crop' };
      }
    }
    try {
      const config = createBuildConfig({
        kind: draft.kind,
        preset: draft.preset,
        custom: draft.preset === 'custom' ? { ...draft.custom } : {},
        seed: draft.seed,
        crop: draft.crop,
      });
      const area = areaOf(config);
      if (area !== null) {
        const shape = buildShape(config);
        const [rows, cols] =
          shape.type === 'grid' ? [shape.rows, shape.cols] : shape.axis === 'vertical' ? [1, shape.count] : [shape.count, 1];
        if (Math.floor(area.width / cols) < 1 || Math.floor(area.height / rows) < 1) {
          return { config: null, message: messages.tooSmall, field: 'crop' };
        }
      }
      return { config, message: null, field: null };
    } catch (e) {
      return { config: null, message: e instanceof Error ? e.message : String(e), field: null };
    }
  }

  function areaOf(config: Pick<BuildConfig, 'crop'>): CropRect | null {
    if (config.crop !== null) return config.crop;
    return imageSize === null ? null : { x: 0, y: 0, width: imageSize.width, height: imageSize.height };
  }

  function drawPreview(config: BuildConfig | null): void {
    const showStage = src !== null && !imageFailed;
    stage.hidden = !showStage;
    placeholder.hidden = showStage && imageSize !== null;
    placeholder.textContent = imageFailed ? messages.imageError : src === null ? messages.noImage : '';
    if (src !== null && imageSize === null && !imageFailed) placeholder.hidden = true;
    grid.replaceChildren();
    note.textContent = draft.kind === 'jigsaw' ? messages.approximate : '';
    if (imageSize === null) {
      summary.textContent = '';
      for (const node of [shade, frame, ...handles]) node.setAttribute('visibility', 'hidden');
      return;
    }
    const { width: w, height: h } = imageSize;
    svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
    svg.setAttribute('preserveAspectRatio', 'none');
    const area =
      draft.crop !== null && Number.isFinite(draft.crop.x + draft.crop.y + draft.crop.width + draft.crop.height)
        ? draft.crop
        : { x: 0, y: 0, width: w, height: h };
    for (const node of [shade, frame, ...handles]) node.removeAttribute('visibility');
    shade.setAttribute('d', `M0 0H${w}V${h}H0Z M${area.x} ${area.y}V${area.y + area.height}H${area.x + area.width}V${area.y}Z`);
    frame.setAttribute('x', String(area.x));
    frame.setAttribute('y', String(area.y));
    frame.setAttribute('width', String(Math.max(0, area.width)));
    frame.setAttribute('height', String(Math.max(0, area.height)));
    frame.setAttribute('aria-label', format(messages.cropArea, { x: area.x, y: area.y, width: area.width, height: area.height }));
    frame.setAttribute('aria-roledescription', messages.cropFrame);
    const size = Math.max(6, Math.min(w, h) / 30);
    const corners = {
      nw: [area.x, area.y],
      ne: [area.x + area.width, area.y],
      sw: [area.x, area.y + area.height],
      se: [area.x + area.width, area.y + area.height],
    } as const;
    for (const handle of handles) {
      const corner = handle.getAttribute('data-corner') as keyof typeof corners;
      const [cx, cy] = corners[corner];
      handle.setAttribute('x', String(cx - size / 2));
      handle.setAttribute('y', String(cy - size / 2));
      handle.setAttribute('width', String(size));
      handle.setAttribute('height', String(size));
    }
    if (config === null) {
      summary.textContent = '';
      return;
    }
    const shape = buildShape(config);
    const line = (x1: number, y1: number, x2: number, y2: number): void => {
      const node = doc.createElementNS(SVG, 'line');
      node.setAttribute('x1', String(x1));
      node.setAttribute('y1', String(y1));
      node.setAttribute('x2', String(x2));
      node.setAttribute('y2', String(y2));
      grid.append(node);
    };
    if (shape.type === 'strips') {
      const vertical = shape.axis === 'vertical';
      const span = vertical ? area.width : area.height;
      const cell = Math.floor(span / shape.count);
      const offset = Math.floor((span - cell * shape.count) / 2);
      for (let i = 1; i < shape.count; i++) {
        if (vertical) line(area.x + offset + i * cell, area.y, area.x + offset + i * cell, area.y + area.height);
        else line(area.x, area.y + offset + i * cell, area.x + area.width, area.y + offset + i * cell);
      }
      const axis = messages.choices[shape.axis] ?? shape.axis;
      summary.textContent = format(messages.stripsSummary, { count: shape.count, axis: axis.toLocaleLowerCase(), size: cell });
      return;
    }
    // Same integer cells, centred, as GameFactory (square cells when rotation turns by 90°).
    let cw = Math.floor(area.width / shape.cols);
    let ch = Math.floor(area.height / shape.rows);
    const angles =
      config.kind === 'rotation'
        ? config.preset === 'custom'
          ? config.custom['orientations']
          : PRESET_SHAPES.rotation[config.preset]['orientations']
        : [];
    if (Array.isArray(angles) && (angles.includes(90) || angles.includes(270))) cw = ch = Math.min(cw, ch);
    const ox = area.x + Math.floor((area.width - cw * shape.cols) / 2);
    const oy = area.y + Math.floor((area.height - ch * shape.rows) / 2);
    for (let c = 0; c <= shape.cols; c++) line(ox + c * cw, oy, ox + c * cw, oy + ch * shape.rows);
    for (let r = 0; r <= shape.rows; r++) line(ox, oy + r * ch, ox + cw * shape.cols, oy + r * ch);
    summary.textContent = format(messages.gridSummary, { rows: shape.rows, cols: shape.cols, width: cw, height: ch });
  }

  function update(emit = true): void {
    if (destroyed) return;
    const result = validate();
    error = result.message;
    invalidField = result.field;
    current = result.config === null ? null : buildConfigDocument(result.config);
    errorText.textContent = error ?? '';
    for (const control of fields.querySelectorAll<HTMLElement>('[data-field]')) {
      const name = control.getAttribute('data-field') ?? '';
      const invalid = invalidField !== null && (name === invalidField || (invalidField === 'crop' && name.startsWith('crop.')));
      if (invalid) {
        control.setAttribute('aria-invalid', 'true');
        control.setAttribute('aria-describedby', `${id}-error`);
      } else {
        control.removeAttribute('aria-invalid');
        control.removeAttribute('aria-describedby');
      }
    }
    syncInputs();
    drawPreview(result.config);
    if (emit) {
      const change: ConfiguratorChange = { value: current, draft: draftDocument(), valid: current !== null, error };
      options.onChange?.(change);
      host.dispatchEvent(new CustomEvent('configurator-change', { detail: change, bubbles: true, composed: true }));
    }
  }

  function draftDocument(): Record<string, unknown> {
    const number = (value: unknown): unknown => (typeof value === 'number' && !Number.isFinite(value) ? null : value);
    const custom: Record<string, unknown> = {};
    if (draft.preset === 'custom') {
      for (const field of FIELDS[draft.kind]) {
        if (field.key in draft.custom) custom[field.key] = number(draft.custom[field.key]);
      }
    }
    return {
      format: BUILD_CONFIG_FORMAT,
      version: BUILD_CONFIG_VERSION,
      kind: draft.kind,
      preset: draft.preset,
      ...(draft.preset === 'custom' ? { custom } : {}),
      seed: number(draft.seed),
      ...(draft.crop !== null
        ? { crop: { x: number(draft.crop.x), y: number(draft.crop.y), width: number(draft.crop.width), height: number(draft.crop.height) } }
        : {}),
    };
  }

  function applyValue(value: unknown): void {
    if (value === null || value === undefined) {
      draft = defaults(kinds[0] ?? 'jigsaw');
      if (draft.preset === 'custom') draft.custom = customFromPreset(draft.kind, 'medium');
      return;
    }
    const config = parseBuildConfig(value);
    if (!kinds.includes(config.kind)) {
      throw new PuzzleError('invalid', `config.kind "${config.kind}" is not offered by this configurator.`);
    }
    draft = { kind: config.kind, preset: config.preset, custom: { ...config.custom }, seed: config.seed, crop: config.crop };
  }

  // --- events ----------------------------------------------------------------
  kindSelect.addEventListener('change', () => {
    const kind = kindSelect.value as BuildKind;
    draft.kind = kind;
    draft.custom = draft.preset === 'custom' ? customFromPreset(kind, 'medium') : {};
    if (kind === 'jigsaw' && draft.seed > MAX_JIGSAW_SEED) draft.seed = draft.seed % (MAX_JIGSAW_SEED + 1);
    renderCustom();
    update();
  });
  presetSelect.addEventListener('change', () => {
    const previous = draft.preset;
    draft.preset = presetSelect.value;
    draft.custom = draft.preset === 'custom' ? customFromPreset(draft.kind, previous) : {};
    renderCustom();
    update();
  });
  seedInput.addEventListener('input', () => {
    draft.seed = intOf(seedInput) ?? Number.NaN;
    update();
  });
  seedButton.addEventListener('click', () => {
    api.randomizeSeed();
  });
  for (const key of ['x', 'y', 'width', 'height'] as const) {
    cropInputs[key].addEventListener('input', () => {
      const base = draft.crop ?? { x: 0, y: 0, width: imageSize?.width ?? 1, height: imageSize?.height ?? 1 };
      const value = intOf(cropInputs[key]);
      draft.crop = { ...base, [key]: value ?? Number.NaN };
      update();
    });
  }
  cropReset.addEventListener('click', () => {
    api.resetCrop();
  });

  image.addEventListener('load', () => {
    if (image.naturalWidth > 0 && image.naturalHeight > 0) {
      imageSize = { width: image.naturalWidth, height: image.naturalHeight };
      imageFailed = false;
    }
    update(false);
  });
  image.addEventListener('error', () => {
    imageSize = null;
    imageFailed = src !== null;
    update(false);
  });

  // Crop dragging: move the frame or resize from a corner, in image pixels.
  let gesture: { pointer: number; mode: 'move' | keyof typeof cornerSigns; start: CropRect; x: number; y: number } | null = null;
  const cornerSigns = { nw: [1, 1, -1, -1], ne: [0, 1, 1, -1], sw: [1, 0, -1, 1], se: [0, 0, 1, 1] } as const;
  const toImage = (event: PointerEvent): { x: number; y: number } => {
    const rect = svg.getBoundingClientRect();
    const size = imageSize ?? { width: 1, height: 1 };
    return {
      x: rect.width > 0 ? ((event.clientX - rect.left) / rect.width) * size.width : 0,
      y: rect.height > 0 ? ((event.clientY - rect.top) / rect.height) * size.height : 0,
    };
  };
  const clampCrop = (crop: CropRect): CropRect => {
    const size = imageSize ?? { width: crop.x + crop.width, height: crop.y + crop.height };
    const width = Math.max(1, Math.min(Math.round(crop.width), size.width));
    const height = Math.max(1, Math.min(Math.round(crop.height), size.height));
    return {
      x: Math.max(0, Math.min(Math.round(crop.x), size.width - width)),
      y: Math.max(0, Math.min(Math.round(crop.y), size.height - height)),
      width,
      height,
    };
  };
  svg.addEventListener('pointerdown', (event) => {
    if (disabled || imageSize === null || event.button !== 0) return;
    const targetNode = event.target as Element;
    const corner = targetNode.getAttribute('data-corner') as keyof typeof cornerSigns | null;
    if (targetNode !== frame && corner === null) return;
    event.preventDefault();
    const start = draft.crop ?? { x: 0, y: 0, width: imageSize.width, height: imageSize.height };
    const point = toImage(event);
    gesture = { pointer: event.pointerId, mode: corner ?? 'move', start, x: point.x, y: point.y };
    try {
      svg.setPointerCapture(event.pointerId);
    } catch {
      // The pointer is already gone (or synthetic); moves still reach the stage.
    }
    frame.focus({ preventScroll: true });
  });
  svg.addEventListener('pointermove', (event) => {
    if (gesture === null || event.pointerId !== gesture.pointer) return;
    const point = toImage(event);
    const dx = point.x - gesture.x;
    const dy = point.y - gesture.y;
    const s = gesture.start;
    if (gesture.mode === 'move') {
      draft.crop = clampCrop({ ...s, x: s.x + dx, y: s.y + dy });
    } else {
      const [mx, my, mw, mh] = cornerSigns[gesture.mode];
      const size = imageSize ?? { width: Infinity, height: Infinity };
      let x = s.x + mx * dx;
      let y = s.y + my * dy;
      let width = s.width + mw * dx;
      let height = s.height + mh * dy;
      if (x < 0) {
        width += x;
        x = 0;
      }
      if (y < 0) {
        height += y;
        y = 0;
      }
      width = Math.min(Math.max(1, width), size.width - x);
      height = Math.min(Math.max(1, height), size.height - y);
      draft.crop = clampCrop({
        x: mx === 1 ? Math.min(x, s.x + s.width - 1) : x,
        y: my === 1 ? Math.min(y, s.y + s.height - 1) : y,
        width,
        height,
      });
    }
    update();
  });
  const endGesture = (event: PointerEvent): void => {
    if (gesture === null || event.pointerId !== gesture.pointer) return;
    gesture = null;
    if (svg.hasPointerCapture(event.pointerId)) svg.releasePointerCapture(event.pointerId);
  };
  svg.addEventListener('pointerup', endGesture);
  svg.addEventListener('pointercancel', endGesture);

  frame.addEventListener('keydown', (event: KeyboardEvent) => {
    if (disabled || imageSize === null) return;
    const deltas: Readonly<Record<string, readonly [number, number]>> = {
      ArrowLeft: [-1, 0],
      ArrowRight: [1, 0],
      ArrowUp: [0, -1],
      ArrowDown: [0, 1],
    };
    const delta = deltas[event.key];
    if (delta === undefined) return;
    event.preventDefault();
    const step = Math.max(1, Math.round(Math.min(imageSize.width, imageSize.height) / 50));
    const crop = draft.crop ?? { x: 0, y: 0, width: imageSize.width, height: imageSize.height };
    draft.crop = event.shiftKey
      ? clampCrop({ ...crop, width: crop.width + delta[0] * step, height: crop.height + delta[1] * step })
      : clampCrop({ ...crop, x: crop.x + delta[0] * step, y: crop.y + delta[1] * step });
    update();
  });

  // --- API -------------------------------------------------------------------
  const api: Configurator = {
    element: host,
    get value() {
      return current;
    },
    get draft() {
      return draftDocument();
    },
    get valid() {
      return current !== null;
    },
    get error() {
      return error;
    },
    get imageSize() {
      return imageSize;
    },
    setValue(value: unknown): void {
      try {
        applyValue(value);
        renderStatic();
        update(false);
      } catch (e) {
        draft = defaults(kinds[0] ?? 'jigsaw');
        renderStatic();
        update(false);
        error = e instanceof Error ? e.message : String(e);
        errorText.textContent = error;
        current = null;
      }
    },
    setSource(next: string | null): void {
      src = next === '' ? null : next;
      imageSize = null;
      imageFailed = false;
      if (src === null) image.removeAttribute('src');
      else image.src = src;
      update(false);
    },
    setKinds(next: readonly BuildKind[]): void {
      kinds = next.length > 0 ? [...next] : [...BUILD_KINDS];
      const replaced = !kinds.includes(draft.kind);
      if (replaced) {
        draft.kind = kinds[0] ?? 'jigsaw';
        draft.custom = draft.preset === 'custom' ? customFromPreset(draft.kind, 'medium') : {};
      }
      renderStatic();
      // Only a forced mode change is an edit the host must hear about.
      update(replaced);
    },
    setDisabled(next: boolean): void {
      disabled = next;
      // The custom element reflects `disabled` itself; only a plain host gets a marker.
      if (ownsHost) host.toggleAttribute('data-disabled', next);
      update(false);
    },
    setLocale(locale?: string, overrides?: Partial<ConfiguratorMessages>): void {
      messages = resolveConfiguratorMessages(locale, overrides ?? options.messages);
      renderStatic();
      update(false);
    },
    randomizeSeed(): void {
      if (disabled) return;
      const max = draft.kind === 'jigsaw' ? MAX_JIGSAW_SEED : MAX_SEED;
      draft.seed = Math.floor(random() * (max + 1)) % (max + 1);
      update();
    },
    resetCrop(): void {
      if (disabled) return;
      draft.crop = null;
      update();
    },
    destroy(): void {
      if (destroyed) return;
      destroyed = true;
      shadow.replaceChildren();
      if (ownsHost) host.remove();
    },
  };

  if (options.disabled === true && ownsHost) host.setAttribute('data-disabled', '');
  api.setValue(options.value ?? null);
  if (options.src !== undefined && options.src !== null) api.setSource(options.src);
  return api;
}
