import { playArea, renderedRect, resolutionImage, resolutionLength } from '@offline-agency/image-puzzle-engine';
import type { Action, Board, JigsawPiece, Rect, Tile } from '@offline-agency/image-puzzle-engine';
import { el, pct } from '../dom.js';
import { format } from '../messages.js';
import { backgroundFor, loadImage } from './media.js';
import type { PendingImage } from './media.js';
import type { ModeView, ViewContext } from './types.js';

const SVG = 'http://www.w3.org/2000/svg';
const DRAG_THRESHOLD = 4;
/**
 * Responsive pieces ask for 1.5 times their displayed density: a set drawn
 * at nearly its own size is resampled with a coarse filter by some engines
 * (WebKit), which widens the anti-aliased seams between placed pieces.
 */
const DENSITY_MARGIN = 1.5;

interface PieceView {
  readonly tile: Tile;
  readonly rendered: Rect;
  readonly button: HTMLButtonElement;
  /** An <img>, or a canvas holding the piece's frame of the atlas. */
  readonly image: HTMLElement;
}

/**
 * Jigsaw view on the definition's world plane (source pixels). Pieces are the
 * PHP-exported images; their PHP-generated outline is the hit area and the
 * focus ring, so non-rectangular pieces are grabbed by their real shape.
 *
 * Alternative images never change the geometry: with media.atlas every piece
 * shows its frame of one spritesheet; with media.resolutions each piece image
 * gets a srcset and sizes from its displayed width, so the browser picks the
 * set for the current size, zoom and pixel density.
 */
export function createJigsawView(ctx: ViewContext): ModeView {
  const { doc, definition } = ctx;
  const area = playArea(definition);
  const crop = definition.crop;
  const minCell = Math.min(...definition.tiles.map((tile) => Math.min(tile.rect.width, tile.rect.height)));
  const step = Math.max(1, Math.round(minCell / 8));

  const instructionsId = `ipp-instructions-${Math.random().toString(36).slice(2, 10)}`;
  const element = el(doc, 'div', { class: 'board-wrap' });
  const instructions = el(doc, 'p', { class: 'sr-only', id: instructionsId }, ctx.messages().instructions['jigsaw'] ?? '');
  const viewport = el(doc, 'div', { class: 'viewport', part: 'viewport' });
  const stage = el(doc, 'div', {
    class: 'stage board',
    part: 'board',
    role: 'group',
    'aria-label': ctx.messages().label,
    'aria-describedby': instructionsId,
    'data-kind': 'jigsaw',
    'data-loading': 'true',
  });
  stage.style.aspectRatio = `${area.width} / ${area.height}`;
  stage.style.width = '100%';
  viewport.append(stage);
  element.append(instructions, viewport);

  const box = (rect: Rect): Partial<CSSStyleDeclaration> => ({
    left: pct(((rect.x - area.x) / area.width) * 100),
    top: pct(((rect.y - area.y) / area.height) * 100),
    width: pct((rect.width / area.width) * 100),
    height: pct((rect.height / area.height) * 100),
  });

  const boardArea = el(doc, 'div', { class: 'board-area', part: 'board-area' });
  Object.assign(boardArea.style, box(crop));
  const guides = doc.createElementNS(SVG, 'svg');
  guides.setAttribute('class', 'guides');
  guides.setAttribute('viewBox', `${area.x} ${area.y} ${area.width} ${area.height}`);
  guides.setAttribute('preserveAspectRatio', 'none');
  guides.setAttribute('aria-hidden', 'true');
  const preview = el(doc, 'div', { class: 'preview', part: 'preview', hidden: '' });
  Object.assign(preview.style, backgroundFor(ctx.asset(definition.media.image), crop, definition.source), box(crop), { opacity: '0.4' });
  stage.append(boardArea, guides, preview);

  let pending = 0;
  let failed = false;
  const atlas = definition.media.atlas;
  const resolutions = definition.media.resolutions ?? [];
  let atlasLoad: PendingImage | null = null;
  const atlasUrl = atlas === undefined ? null : ctx.asset(atlas.image);
  const frames: { readonly canvas: HTMLCanvasElement; readonly frame: Rect }[] = [];
  if (atlasUrl !== null) {
    pending++;
    atlasLoad = loadImage(
      atlasUrl,
      (sheet) => {
        drawFrames(sheet);
        onImageLoad();
      },
      () => {
        onImageError(atlasUrl);
      },
    );
  }
  /** Responsive images waiting for their first measured size (see `sizes`). */
  const responsive: { readonly image: HTMLImageElement; readonly rendered: Rect; readonly srcset: string; readonly url: string }[] = [];

  const pieces: PieceView[] = definition.tiles.map((tile) => {
    const rendered = renderedRect(tile);
    const path = typeof tile.attributes['path'] === 'string' ? tile.attributes['path'] : null;
    const outlineData =
      path ??
      `M${rendered.x} ${rendered.y} L${rendered.x + rendered.width} ${rendered.y} L${rendered.x + rendered.width} ${rendered.y + rendered.height} L${rendered.x} ${rendered.y + rendered.height} Z`;
    const guide = doc.createElementNS(SVG, 'path');
    guide.setAttribute('d', outlineData);
    guides.append(guide);

    const button = el(doc, 'button', { type: 'button', class: 'piece', part: 'piece', 'data-piece': tile.id });
    Object.assign(button.style, { width: pct((rendered.width / area.width) * 100), height: pct((rendered.height / area.height) * 100) });
    const frame = atlas?.frames[tile.id];
    const image = frame === undefined ? pieceImage(tile, rendered) : atlasFrame(frame);
    const svg = doc.createElementNS(SVG, 'svg');
    svg.setAttribute('viewBox', `${rendered.x} ${rendered.y} ${rendered.width} ${rendered.height}`);
    svg.setAttribute('preserveAspectRatio', 'none');
    svg.setAttribute('aria-hidden', 'true');
    const outline = doc.createElementNS(SVG, 'path');
    outline.setAttribute('class', 'outline');
    outline.setAttribute('d', outlineData);
    svg.append(outline);
    button.append(image, svg);
    stage.append(button);
    return { tile, rendered, button, image };
  });
  const byId = new Map(pieces.map((piece) => [piece.tile.id, piece]));

  /**
   * A canvas holding the piece's frame of the atlas, filled once the atlas has
   * loaded. The browser then scales it like a separate piece image: CSS
   * backgrounds, SVG viewports and clipping boxes all lose part of the
   * anti-aliased edge at the piece box and widen the seams between pieces.
   */
  function atlasFrame(frame: Rect): HTMLCanvasElement {
    const canvas = el(doc, 'canvas', { class: 'piece-image', width: String(frame.width), height: String(frame.height) });
    frames.push({ canvas, frame });
    return canvas;
  }

  function drawFrames(sheet: HTMLImageElement): void {
    for (const { canvas, frame } of frames) {
      canvas.getContext('2d')?.drawImage(sheet, frame.x, frame.y, frame.width, frame.height, 0, 0, frame.width, frame.height);
    }
  }

  function pieceImage(tile: Tile, rendered: Rect): HTMLImageElement {
    const path = String(tile.attributes['image']);
    const url = ctx.asset(path);
    const image = el(doc, 'img', { alt: '', draggable: 'false', decoding: 'async' });
    pending++;
    image.addEventListener('load', onImageLoad, { once: true });
    image.addEventListener(
      'error',
      () => {
        onImageError(url);
      },
      { once: true },
    );
    if (resolutions.length === 0) {
      image.src = url;
      return image;
    }
    const candidates = resolutions.map(
      (resolution) =>
        `${ctx.asset(resolutionImage(resolution, path))} ${resolutionLength(resolution, rendered.width, definition.source.width)}w`,
    );
    responsive.push({ image, rendered, srcset: [...candidates, `${url} ${rendered.width}w`].join(', '), url });
    return image;
  }

  /**
   * The displayed width of each responsive piece in CSS pixels, times
   * DENSITY_MARGIN. Sources are assigned on the first measurement, so the
   * first request already matches the layout; without ResizeObserver a
   * viewport-relative estimate is used.
   */
  function sizes(stageWidth: number | null): void {
    for (const { image, rendered, srcset, url } of responsive) {
      const fraction = (rendered.width / area.width) * DENSITY_MARGIN;
      image.sizes = stageWidth === null ? `${(fraction * 100).toFixed(3)}vw` : `${Math.max(1, Math.ceil(fraction * stageWidth))}px`;
      if (image.srcset === '') {
        image.srcset = srcset;
        image.src = url;
      }
    }
  }
  const observer =
    responsive.length > 0 && typeof ResizeObserver === 'function'
      ? new ResizeObserver((entries) => {
          const width = entries[0]?.contentRect.width ?? 0;
          if (width > 0) sizes(width);
        })
      : null;
  if (observer === null) sizes(null);
  else observer.observe(stage);

  function onImageLoad(): void {
    pending--;
    if (pending === 0 && !failed) stage.setAttribute('data-loading', 'false');
  }
  function onImageError(url: string): void {
    if (failed) return;
    failed = true;
    ctx.assetError(url);
  }

  let board = currentPieces(ctx.engine.exportState().board);
  /** Keyboard/pointer positions not yet dispatched. */
  const moving = new Map<string, { x: number; y: number }>();
  let zIndex = 1;
  let hintTimer: ReturnType<typeof setTimeout> | null = null;
  let drag: {
    id: string;
    pointer: number;
    startX: number;
    startY: number;
    fromX: number;
    fromY: number;
    scale: number;
    active: boolean;
  } | null = null;

  function reveal(element: HTMLElement): void {
    // Absent in some non-browser DOMs (jsdom).
    if (typeof element.scrollIntoView === 'function') element.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }

  function currentPieces(value: Board): readonly JigsawPiece[] {
    if (!('pieces' in value)) throw new Error('Board does not match the jigsaw mode');
    return value.pieces;
  }

  function clamp(piece: PieceView, x: number, y: number): { x: number; y: number } {
    return {
      x: Math.round(Math.min(Math.max(x, area.x), area.x + area.width - piece.rendered.width)),
      y: Math.round(Math.min(Math.max(y, area.y), area.y + area.height - piece.rendered.height)),
    };
  }

  function place(piece: PieceView, x: number, y: number): void {
    piece.button.style.left = pct(((x - area.x) / area.width) * 100);
    piece.button.style.top = pct(((y - area.y) / area.height) * 100);
  }

  function render(): void {
    const complete = ctx.engine.status === 'complete';
    const m = ctx.messages();
    pieces.forEach((piece, i) => {
      const state = board[i];
      if (state === undefined) return;
      const position = moving.get(piece.tile.id) ?? state;
      place(piece, position.x, position.y);
      piece.button.classList.toggle('is-placed', state.placed);
      piece.button.classList.toggle('highlight', state.placed && definition.assistance.highlightCorrect);
      piece.button.tabIndex = state.placed || complete ? -1 : 0;
      piece.button.setAttribute('aria-disabled', String(state.placed || complete));
      piece.button.setAttribute('aria-label', format(state.placed ? m.piecePlaced : m.piece, { n: piece.tile.home + 1 }));
      if (state.placed) piece.button.style.zIndex = '0';
    });
  }

  function raise(piece: PieceView): void {
    piece.button.style.zIndex = String(++zIndex);
  }

  function commit(piece: PieceView, x: number, y: number): void {
    moving.delete(piece.tile.id);
    const m = ctx.messages();
    const ok = ctx.dispatch({ type: 'place', piece: piece.tile.id, x, y });
    const index = definition.tiles.indexOf(piece.tile);
    if (!ok) {
      ctx.announce(m.illegal);
      render();
      return;
    }
    const placed = board[index]?.placed ?? false;
    ctx.announce(format(placed ? m.placed : m.dropped, { n: piece.tile.home + 1 }));
    if (placed) focusNext(index);
  }

  function focusNext(from: number): void {
    for (let k = 1; k <= pieces.length; k++) {
      const i = (from + k) % pieces.length;
      if (board[i]?.placed === false) {
        pieces[i]?.button.focus();
        return;
      }
    }
  }

  function pieceFrom(event: Event): PieceView | undefined {
    const button = (event.target as Element | null)?.closest('button.piece');
    return button instanceof HTMLElement && stage.contains(button) ? byId.get(button.dataset['piece'] ?? '') : undefined;
  }

  function onKeyDown(event: KeyboardEvent): void {
    const piece = pieceFrom(event);
    if (piece === undefined) return;
    const index = definition.tiles.indexOf(piece.tile);
    const state = board[index];
    if (state === undefined || state.placed || ctx.engine.status === 'complete') return;
    const current = moving.get(piece.tile.id) ?? state;
    const amount = event.shiftKey ? step * 5 : step;
    const delta: Record<string, [number, number]> = { ArrowUp: [0, -1], ArrowDown: [0, 1], ArrowLeft: [-1, 0], ArrowRight: [1, 0] };
    const d = delta[event.key];
    if (d !== undefined) {
      event.preventDefault();
      const next = clamp(piece, current.x + d[0] * amount, current.y + d[1] * amount);
      moving.set(piece.tile.id, next);
      raise(piece);
      place(piece, next.x, next.y);
      reveal(piece.button);
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      if (moving.has(piece.tile.id)) commit(piece, current.x, current.y);
    } else if (event.key === 'Escape' && moving.has(piece.tile.id)) {
      event.preventDefault();
      moving.delete(piece.tile.id);
      render();
    }
  }

  function onPointerDown(event: PointerEvent): void {
    const piece = pieceFrom(event);
    if (piece === undefined || event.button !== 0 || ctx.engine.status === 'complete') return;
    const index = definition.tiles.indexOf(piece.tile);
    const state = board[index];
    if (state === undefined || state.placed) return;
    event.preventDefault();
    const current = moving.get(piece.tile.id) ?? state;
    const scale = area.width / Math.max(1, stage.getBoundingClientRect().width);
    drag = {
      id: piece.tile.id,
      pointer: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      fromX: current.x,
      fromY: current.y,
      scale,
      active: false,
    };
    stage.setPointerCapture(event.pointerId);
    raise(piece);
    piece.button.focus({ preventScroll: true });
  }

  function onPointerMove(event: PointerEvent): void {
    if (drag === null || event.pointerId !== drag.pointer) return;
    const piece = byId.get(drag.id) as PieceView;
    const dx = event.clientX - drag.startX;
    const dy = event.clientY - drag.startY;
    if (!drag.active && Math.hypot(dx, dy) < DRAG_THRESHOLD) return;
    drag.active = true;
    piece.button.classList.add('is-dragging');
    const next = clamp(piece, drag.fromX + dx * drag.scale, drag.fromY + dy * drag.scale);
    moving.set(piece.tile.id, next);
    place(piece, next.x, next.y);
  }

  function onPointerEnd(event: PointerEvent): void {
    if (drag === null || event.pointerId !== drag.pointer) return;
    const { id, active } = drag;
    drag = null;
    if (stage.hasPointerCapture(event.pointerId)) stage.releasePointerCapture(event.pointerId);
    const piece = byId.get(id) as PieceView;
    piece.button.classList.remove('is-dragging');
    const position = moving.get(id);
    if (!active || position === undefined || event.type === 'pointercancel') {
      moving.delete(id);
      render();
      return;
    }
    commit(piece, position.x, position.y);
  }

  stage.addEventListener('keydown', onKeyDown);
  stage.addEventListener('pointerdown', onPointerDown);
  stage.addEventListener('pointermove', onPointerMove);
  stage.addEventListener('pointerup', onPointerEnd);
  stage.addEventListener('pointercancel', onPointerEnd);
  render();

  return {
    element,
    update(next, external) {
      board = currentPieces(next);
      if (external) moving.clear();
      render();
    },
    showHint(action: Action) {
      if (action.type !== 'place') return;
      const piece = byId.get(action.piece);
      if (piece === undefined) return;
      for (const other of pieces) other.button.classList.remove('is-hint');
      piece.button.classList.add('is-hint');
      raise(piece);
      piece.button.focus();
      reveal(piece.button);
      if (hintTimer !== null) clearTimeout(hintTimer);
      hintTimer = setTimeout(() => {
        piece.button.classList.remove('is-hint');
      }, 2500);
    },
    setPreview(visible) {
      preview.hidden = !visible;
    },
    setZoom(zoom) {
      stage.style.width = pct(Math.max(1, zoom) * 100);
    },
    focus() {
      const index = board.findIndex((piece) => !piece.placed);
      (pieces[index] ?? pieces[0])?.button.focus();
    },
    destroy() {
      if (hintTimer !== null) clearTimeout(hintTimer);
      stage.removeEventListener('keydown', onKeyDown);
      stage.removeEventListener('pointerdown', onPointerDown);
      stage.removeEventListener('pointermove', onPointerMove);
      stage.removeEventListener('pointerup', onPointerEnd);
      stage.removeEventListener('pointercancel', onPointerEnd);
      observer?.disconnect();
      atlasLoad?.cancel();
      for (const piece of pieces) piece.image.removeAttribute('src');
      element.remove();
    },
  };
}
