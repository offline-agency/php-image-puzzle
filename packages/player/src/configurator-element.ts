import { BUILD_KINDS } from '@offline-agency/image-puzzle-engine';
import type { BuildConfigDocument, BuildKind } from '@offline-agency/image-puzzle-engine';
import { createConfigurator } from './configurator.js';
import type { Configurator, ConfiguratorChange } from './configurator.js';

export const CONFIGURATOR_TAG = 'image-puzzle-configurator';
export const CONFIGURATOR_CHANGE_EVENT = 'configurator-change';

/**
 * Public surface of <image-puzzle-configurator>. It is form-associated: with
 * a `name` it submits the configuration JSON, and an invalid configuration
 * makes the form invalid.
 */
export interface ImagePuzzleConfiguratorElement extends HTMLElement {
  /** The valid configuration document, or null. */
  get value(): BuildConfigDocument | null;
  /** Accepts a document object, a JSON string or null (defaults). */
  set value(value: unknown);
  /** Reflects `src`: preview image URL. */
  src: string | null;
  /** Reflects `kinds` (space separated): modes offered. */
  kinds: readonly BuildKind[];
  /** Reflects `disabled`. */
  disabled: boolean;
  /** Reflects `locale`. */
  locale: string | null;
  /** Reflects `name` (form field name). */
  name: string | null;
  readonly valid: boolean;
  readonly error: string | null;
  readonly configurator: Configurator;
  randomizeSeed(): void;
  resetCrop(): void;
}

declare global {
  interface HTMLElementTagNameMap {
    'image-puzzle-configurator': ImagePuzzleConfiguratorElement;
  }
  interface HTMLElementEventMap {
    'configurator-change': CustomEvent<ConfiguratorChange>;
  }
}

function parseKinds(value: string | null): BuildKind[] {
  if (value === null) return [];
  return value.split(/[\s,]+/).filter((kind): kind is BuildKind => (BUILD_KINDS as readonly string[]).includes(kind));
}

/**
 * Registers the configurator element. Idempotent; returns undefined outside a
 * browser (SSR).
 */
export function defineImagePuzzleConfigurator(tag: string = CONFIGURATOR_TAG): CustomElementConstructor | undefined {
  if (typeof customElements === 'undefined' || typeof HTMLElement === 'undefined') {
    return undefined;
  }
  const existing = customElements.get(tag);
  if (existing !== undefined) {
    return existing;
  }

  class Element extends HTMLElement implements ImagePuzzleConfiguratorElement {
    static formAssociated = true;
    static observedAttributes = ['src', 'value', 'kinds', 'disabled', 'locale'];

    readonly #configurator: Configurator;
    readonly #internals: ElementInternals | null;
    #initialValue: unknown = null;

    constructor() {
      super();
      const shadow = this.shadowRoot ?? this.attachShadow({ mode: 'open' });
      this.#internals = typeof this.attachInternals === 'function' ? this.attachInternals() : null;
      this.#configurator = createConfigurator(shadow, {
        onChange: (change) => {
          this.#syncForm(change);
        },
      });
      this.#syncForm({ value: this.#configurator.value, valid: this.#configurator.valid, error: this.#configurator.error });
    }

    connectedCallback(): void {
      // Properties set before the element was upgraded shadow the accessors.
      for (const name of ['value', 'src', 'kinds', 'disabled', 'locale', 'name'] as const) {
        if (Object.prototype.hasOwnProperty.call(this, name)) {
          const value: unknown = (this as Record<string, unknown>)[name];
          Reflect.deleteProperty(this, name);
          (this as Record<string, unknown>)[name] = value;
        }
      }
    }

    attributeChangedCallback(name: string, oldValue: string | null, value: string | null): void {
      if (oldValue === value) return;
      switch (name) {
        case 'src':
          this.#configurator.setSource(value);
          break;
        case 'value':
          this.#initialValue = value;
          this.#setValue(value);
          break;
        case 'kinds':
          this.#configurator.setKinds(parseKinds(value));
          break;
        case 'disabled':
          this.#configurator.setDisabled(value !== null);
          break;
        case 'locale':
          this.#configurator.setLocale(value ?? undefined);
          break;
      }
      this.#syncForm({ value: this.#configurator.value, valid: this.#configurator.valid, error: this.#configurator.error });
    }

    get value(): BuildConfigDocument | null {
      return this.#configurator.value;
    }
    set value(value: unknown) {
      this.#setValue(value);
      this.#syncForm({ value: this.#configurator.value, valid: this.#configurator.valid, error: this.#configurator.error });
    }

    get src(): string | null {
      return this.getAttribute('src');
    }
    set src(value: string | null) {
      if (value === null) this.removeAttribute('src');
      else this.setAttribute('src', value);
    }

    get kinds(): readonly BuildKind[] {
      const kinds = parseKinds(this.getAttribute('kinds'));
      return kinds.length > 0 ? kinds : [...BUILD_KINDS];
    }
    set kinds(value: readonly BuildKind[]) {
      this.setAttribute('kinds', value.join(' '));
    }

    get disabled(): boolean {
      return this.hasAttribute('disabled');
    }
    set disabled(value: boolean) {
      this.toggleAttribute('disabled', value);
    }

    get locale(): string | null {
      return this.getAttribute('locale');
    }
    set locale(value: string | null) {
      if (value === null) this.removeAttribute('locale');
      else this.setAttribute('locale', value);
    }

    get name(): string | null {
      return this.getAttribute('name');
    }
    set name(value: string | null) {
      if (value === null) this.removeAttribute('name');
      else this.setAttribute('name', value);
    }

    get valid(): boolean {
      return this.#configurator.valid;
    }
    get error(): string | null {
      return this.#configurator.error;
    }
    get configurator(): Configurator {
      return this.#configurator;
    }

    randomizeSeed(): void {
      this.#configurator.randomizeSeed();
    }
    resetCrop(): void {
      this.#configurator.resetCrop();
    }

    /** Form reset restores the `value` attribute. */
    formResetCallback(): void {
      this.#setValue(this.#initialValue);
      this.#syncForm({ value: this.#configurator.value, valid: this.#configurator.valid, error: this.#configurator.error });
    }

    formDisabledCallback(disabled: boolean): void {
      this.#configurator.setDisabled(disabled || this.hasAttribute('disabled'));
    }

    #setValue(value: unknown): void {
      if (typeof value === 'string') {
        let parsed: unknown;
        try {
          parsed = value.trim() === '' ? null : JSON.parse(value);
        } catch {
          parsed = value;
        }
        this.#configurator.setValue(parsed);
      } else {
        this.#configurator.setValue(value ?? null);
      }
    }

    #syncForm(change: ConfiguratorChange): void {
      const internals = this.#internals;
      if (internals === null || typeof internals.setFormValue !== 'function') return;
      internals.setFormValue(change.value === null ? null : JSON.stringify(change.value));
      if (change.valid) {
        internals.setValidity({});
      } else {
        internals.setValidity({ customError: true }, change.error ?? 'Invalid configuration.');
      }
    }
  }

  customElements.define(tag, Element);
  return Element;
}
