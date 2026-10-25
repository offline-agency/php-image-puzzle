import type { Rect } from '@offline-agency/image-puzzle-engine';
import { cssUrl, pct } from '../dom.js';

/** Background declarations that show `rect` of a `source`-sized image across the whole element. */
export function backgroundFor(
  url: string,
  rect: Rect,
  source: { readonly width: number; readonly height: number },
): Partial<CSSStyleDeclaration> {
  const axis = (offset: number, size: number, total: number): string => (total === size ? '0%' : pct((offset / (total - size)) * 100));
  return {
    backgroundImage: cssUrl(url),
    backgroundSize: `${pct((source.width / rect.width) * 100)} ${pct((source.height / rect.height) * 100)}`,
    backgroundPosition: `${axis(rect.x, rect.width, source.width)} ${axis(rect.y, rect.height, source.height)}`,
  };
}

export interface PendingImage {
  cancel(): void;
}

/** Loads an image once; cancel() detaches the handlers and releases the request. */
export function loadImage(url: string, onLoad: (image: HTMLImageElement) => void, onError: () => void): PendingImage {
  const image = new Image();
  let done = false;
  image.onload = () => {
    if (!done) {
      done = true;
      onLoad(image);
    }
  };
  image.onerror = () => {
    if (!done) {
      done = true;
      onError();
    }
  };
  image.decoding = 'async';
  image.src = url;
  return {
    cancel() {
      done = true;
      image.onload = null;
      image.onerror = null;
      image.removeAttribute('src');
    },
  };
}
