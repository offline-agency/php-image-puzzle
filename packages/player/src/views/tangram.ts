import {
  playArea,
  tangramAssignment,
  tangramHome,
  tangramPath,
  tangramPolygon,
  tangramSlotsOf,
  tangramUnit,
} from '@offline-agency/image-puzzle-engine';
import type { Action, Board, TangramBoard, TangramPose, Tile } from '@offline-agency/image-puzzle-engine';
import { el } from '../dom.js';
import { format } from '../messages.js';
import { loadImage } from './media.js';
import type { ModeView, ViewContext } from './types.js';

const SVG = 'http://www.w3.org/2000/svg';
const DRAG_THRESHOLD = 4;

interface PieceView {
  readonly tile: Tile;
  readonly index: number;
  readonly group: SVGGElement;
  /** Centroid of the solved piece: the pose origin. */
  readonly cx: number;
  readonly cy: number;
}

/**
 * Tangram view: one SVG on the definition's world plane (source pixels).
 * Each piece is the source image clipped to its solved outline and moved by
 * its pose (translate, rotate, mirror around the centroid, the same order as
 * tangramPolygon), so the picture always travels with the piece. The target
 * silhouette is drawn underneath from the shared slot geometry.
 *
 * Pieces are focusable SVG groups with role="button": arrows move the focused
 * piece (Shift for larger steps) and Enter drops it; R turns it clockwise by
 * 45°, Shift+R counter-clockwise, F mirrors it, each committed at once.
 * Pointer users drag pieces and use the rotate/flip buttons on the last
 * piece they touched.
 */
export function createTangramView(ctx: ViewContext): ModeView {
  const { doc, definition } = ctx;
  const area = playArea(definition);
  const unit = tangramUnit(definition);
  const step = Math.max(1, Math.round(unit / 12));
  const imageUrl = ctx.asset(definition.media.image);
  const uid = Math.random().toString(36).slice(2, 10);

  const element = el(doc, 'div', { class: 'board-wrap' });
  const instructions = el(doc, 'p', { class: 'sr-only', id: `ipp-instructions-${uid}` });
  const tools = el(doc, 'div', { class: 'tangram-tools', role: 'group' });
  const turnBack = el(doc, 'button', { type: 'button', class: 'control', 'data-tool': 'ccw' });
  const turn = el(doc, 'button', { type: 'button', class: 'control', 'data-tool': 'cw' });
  const mirror = el(doc, 'button', { type: 'button', class: 'control', 'data-tool': 'flip' });
  tools.append(turnBack, turn, mirror);

  const stage = doc.createElementNS(SVG, 'svg');
  stage.setAttribute('class', 'tangram board');
  stage.setAttribute('part', 'board');
  stage.setAttribute('role', 'group');
  stage.setAttribute('aria-describedby', instructions.id);
  stage.setAttribute('data-kind', 'tangram');
  stage.setAttribute('data-loading', 'true');
  stage.setAttribute('viewBox', `${area.x} ${area.y} ${area.width} ${area.height}`);
  element.append(instructions, tools, stage);

  const defs = doc.createElementNS(SVG, 'defs');
  const target = doc.createElementNS(SVG, 'g');
  target.setAttribute('class', 'target');
  target.setAttribute('aria-hidden', 'true');
  for (const slot of tangramSlotsOf(definition)) {
    const outline = doc.createElementNS(SVG, 'path');
    outline.setAttribute('d', tangramPath(tangramPolygon(slot.piece, unit, slot.x, slot.y, slot.rotation, slot.flipped)));
    target.append(outline);
  }
  const preview = doc.createElementNS(SVG, 'image');
  preview.setAttribute('class', 'preview');
  preview.setAttribute('part', 'preview');
  preview.setAttribute('href', imageUrl);
  preview.setAttribute('aria-hidden', 'true');
  const { crop, source } = definition;
  // Show only the crop: a nested viewport clips the full-size image.
  const previewFrame = doc.createElementNS(SVG, 'svg');
  previewFrame.setAttribute('x', String(crop.x));
  previewFrame.setAttribute('y', String(crop.y));
  previewFrame.setAttribute('width', String(crop.width));
  previewFrame.setAttribute('height', String(crop.height));
  previewFrame.setAttribute('viewBox', `${crop.x} ${crop.y} ${crop.width} ${crop.height}`);
  previewFrame.setAttribute('display', 'none');
  preview.setAttribute('x', '0');
  preview.setAttribute('y', '0');
  preview.setAttribute('width', String(source.width));
  preview.setAttribute('height', String(source.height));
  previewFrame.append(preview);
  const ghost = doc.createElementNS(SVG, 'path');
  ghost.setAttribute('class', 'ghost');
  ghost.setAttribute('aria-hidden', 'true');
  ghost.setAttribute('display', 'none');
  const layer = doc.createElementNS(SVG, 'g');
  stage.append(defs, target, previewFrame, ghost, layer);

  const pieces: PieceView[] = definition.tiles.map((tile, index) => {
    const home = tangramHome(tile.id, crop.x, crop.y, unit);
    const clip = doc.createElementNS(SVG, 'clipPath');
    clip.setAttribute('id', `ipp-tangram-${uid}-${index}`);
    const clipShape = doc.createElementNS(SVG, 'path');
    clipShape.setAttribute('d', tangramPath(home));
    clip.append(clipShape);
    defs.append(clip);

    const group = doc.createElementNS(SVG, 'g');
    group.setAttribute('class', 'piece');
    group.setAttribute('part', 'piece');
    group.setAttribute('role', 'button');
    group.setAttribute('data-piece', tile.id);
    const picture = doc.createElementNS(SVG, 'image');
    picture.setAttribute('href', imageUrl);
    picture.setAttribute('x', '0');
    picture.setAttribute('y', '0');
    picture.setAttribute('width', String(source.width));
    picture.setAttribute('height', String(source.height));
    picture.setAttribute('preserveAspectRatio', 'none');
    picture.setAttribute('clip-path', `url(#${clip.id})`);
    const outline = doc.createElementNS(SVG, 'path');
    outline.setAttribute('class', 'outline');
    outline.setAttribute('d', tangramPath(home));
    group.append(picture, outline);
    layer.append(group);
    return {
      tile,
      index,
      group,
      cx: home.reduce((sum, [x]) => sum + x, 0) / home.length,
      cy: home.reduce((sum, [, y]) => sum + y, 0) / home.length,
    };
  });
  const byId = new Map(pieces.map((piece) => [piece.tile.id, piece]));

  const image = loadImage(
    imageUrl,
    () => {
      stage.setAttribute('data-loading', 'false');
    },
    () => {
      ctx.assetError(imageUrl);
    },
  );

  let board = posesOf(ctx.engine.exportState().board);
  let placed = new Set<number>();
  /** Keyboard/pointer poses not yet dispatched. */
  const moving = new Map<string, TangramPose>();
  let selected: PieceView | null = null;
  let hintTimer: ReturnType<typeof setTimeout> | null = null;
  let drag: {
    piece: PieceView;
    pointer: number;
    startX: number;
    startY: number;
    from: TangramPose;
    scale: number;
    active: boolean;
  } | null = null;

  function transform(piece: PieceView, pose: TangramPose): string {
    return `translate(${pose.x} ${pose.y}) rotate(${pose.rotation * 45}) scale(${pose.flipped ? -1 : 1} 1) translate(${-piece.cx} ${-piece.cy})`;
  }

  /** Shifts a pose by whole pixels so the piece stays inside the play area. */
  function clamp(piece: PieceView, pose: TangramPose): TangramPose {
    const polygon = tangramPolygon(piece.tile.id, unit, pose.x, pose.y, pose.rotation, pose.flipped);
    const xs = polygon.map(([x]) => x);
    const ys = polygon.map(([, y]) => y);
    const shift = (min: number, max: number, from: number, size: number): number =>
      min < from ? Math.ceil(from - min) : max > from + size ? Math.floor(from + size - max) : 0;
    return {
      ...pose,
      x: Math.round(pose.x) + shift(Math.min(...xs), Math.max(...xs), area.x, area.width),
      y: Math.round(pose.y) + shift(Math.min(...ys), Math.max(...ys), area.y, area.height),
    };
  }

  function describe(piece: PieceView, pose: TangramPose, isPlaced: boolean): string {
    const m = ctx.messages();
    const name = m.tangramPieces[piece.tile.id] ?? piece.tile.id;
    if (isPlaced) return format(m.tangramPlaced, { name });
    return format(pose.flipped ? m.tangramPoseFlipped : m.tangramPose, { name, degrees: pose.rotation * 45 });
  }

  function render(): void {
    const m = ctx.messages();
    instructions.textContent = m.instructions['tangram'] ?? '';
    stage.setAttribute('aria-label', m.label);
    tools.setAttribute('aria-label', m.tangramTools);
    turnBack.textContent = m.tangramTurnBack;
    turn.textContent = m.tangramTurn;
    mirror.textContent = m.tangramFlip;
    const complete = ctx.engine.status === 'complete';
    placed = new Set(tangramAssignment(definition, board).keys());
    for (const piece of pieces) {
      const pose = moving.get(piece.tile.id) ?? board[piece.index];
      if (pose === undefined) continue;
      const isPlaced = placed.has(piece.index) && !moving.has(piece.tile.id);
      piece.group.setAttribute('transform', transform(piece, pose));
      piece.group.classList.toggle('is-placed', isPlaced);
      piece.group.classList.toggle('highlight', isPlaced && definition.assistance.highlightCorrect);
      piece.group.classList.toggle('is-selected', piece === selected);
      piece.group.setAttribute('tabindex', complete ? '-1' : '0');
      piece.group.setAttribute('aria-disabled', String(complete));
      piece.group.setAttribute('aria-label', describe(piece, pose, isPlaced));
    }
    for (const button of [turnBack, turn, mirror]) button.disabled = complete || selected === null;
  }

  /** Draws the piece on top; moving a node drops its focus, so it is restored. */
  function raise(piece: PieceView): void {
    if (layer.lastChild === piece.group) return;
    const focused = piece.group.matches(':focus');
    layer.append(piece.group);
    if (focused) piece.group.focus({ preventScroll: true });
  }

  function select(piece: PieceView): void {
    selected = piece;
    raise(piece);
  }

  function commit(piece: PieceView, pose: TangramPose): void {
    moving.delete(piece.tile.id);
    const m = ctx.messages();
    if (!ctx.dispatch({ type: 'pose', piece: piece.tile.id, ...pose })) {
      ctx.announce(m.illegal);
      render();
      return;
    }
    const now = board[piece.index] ?? pose;
    ctx.announce(describe(piece, now, placed.has(piece.index)));
  }

  function turnBy(piece: PieceView, quarter: number, flip: boolean): void {
    const current = moving.get(piece.tile.id) ?? board[piece.index];
    if (current === undefined || ctx.engine.status === 'complete') return;
    select(piece);
    commit(
      piece,
      clamp(piece, { ...current, rotation: (current.rotation + quarter + 8) % 8, flipped: flip ? !current.flipped : current.flipped }),
    );
  }

  function pieceFrom(event: Event): PieceView | undefined {
    const group = (event.target as Element | null)?.closest('g.piece');
    return group instanceof SVGGElement && stage.contains(group) ? byId.get(group.dataset['piece'] ?? '') : undefined;
  }

  function onKeyDown(event: KeyboardEvent): void {
    const piece = pieceFrom(event);
    if (piece === undefined || ctx.engine.status === 'complete') return;
    const current = moving.get(piece.tile.id) ?? board[piece.index];
    if (current === undefined) return;
    const amount = event.shiftKey ? step * 5 : step;
    const delta: Record<string, [number, number]> = { ArrowUp: [0, -1], ArrowDown: [0, 1], ArrowLeft: [-1, 0], ArrowRight: [1, 0] };
    const d = delta[event.key];
    const key = event.key.toLowerCase();
    if (d !== undefined) {
      event.preventDefault();
      select(piece);
      moving.set(piece.tile.id, clamp(piece, { ...current, x: current.x + d[0] * amount, y: current.y + d[1] * amount }));
      render();
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      if (moving.has(piece.tile.id)) commit(piece, current);
    } else if (event.key === 'Escape' && moving.has(piece.tile.id)) {
      event.preventDefault();
      moving.delete(piece.tile.id);
      render();
    } else if (key === 'r' && !event.ctrlKey && !event.metaKey && !event.altKey) {
      event.preventDefault();
      turnBy(piece, event.shiftKey ? -1 : 1, false);
    } else if (key === 'f' && !event.ctrlKey && !event.metaKey && !event.altKey) {
      event.preventDefault();
      turnBy(piece, 0, true);
    }
  }

  function onFocusIn(event: FocusEvent): void {
    const piece = pieceFrom(event);
    if (piece !== undefined && piece !== selected) {
      selected = piece;
      render();
    }
  }

  function onPointerDown(event: PointerEvent): void {
    const piece = pieceFrom(event);
    if (piece === undefined || event.button !== 0 || ctx.engine.status === 'complete') return;
    const from = moving.get(piece.tile.id) ?? board[piece.index];
    if (from === undefined) return;
    event.preventDefault();
    const scale = area.width / Math.max(1, stage.getBoundingClientRect().width);
    drag = { piece, pointer: event.pointerId, startX: event.clientX, startY: event.clientY, from, scale, active: false };
    stage.setPointerCapture(event.pointerId);
    select(piece);
    piece.group.focus({ preventScroll: true });
    render();
  }

  function onPointerMove(event: PointerEvent): void {
    if (drag === null || event.pointerId !== drag.pointer) return;
    const dx = event.clientX - drag.startX;
    const dy = event.clientY - drag.startY;
    if (!drag.active && Math.hypot(dx, dy) < DRAG_THRESHOLD) return;
    drag.active = true;
    drag.piece.group.classList.add('is-dragging');
    moving.set(drag.piece.tile.id, clamp(drag.piece, { ...drag.from, x: drag.from.x + dx * drag.scale, y: drag.from.y + dy * drag.scale }));
    render();
  }

  function onPointerEnd(event: PointerEvent): void {
    if (drag === null || event.pointerId !== drag.pointer) return;
    const { piece, active } = drag;
    drag = null;
    if (stage.hasPointerCapture(event.pointerId)) stage.releasePointerCapture(event.pointerId);
    piece.group.classList.remove('is-dragging');
    const pose = moving.get(piece.tile.id);
    if (!active || pose === undefined || event.type === 'pointercancel') {
      moving.delete(piece.tile.id);
      render();
      return;
    }
    commit(piece, pose);
  }

  const onTool = (quarter: number, flip: boolean) => (): void => {
    if (selected !== null) {
      turnBy(selected, quarter, flip);
      selected.group.focus({ preventScroll: true });
    }
  };
  const onTurnBack = onTool(-1, false);
  const onTurn = onTool(1, false);
  const onMirror = onTool(0, true);

  stage.addEventListener('keydown', onKeyDown);
  stage.addEventListener('focusin', onFocusIn);
  stage.addEventListener('pointerdown', onPointerDown);
  stage.addEventListener('pointermove', onPointerMove);
  stage.addEventListener('pointerup', onPointerEnd);
  stage.addEventListener('pointercancel', onPointerEnd);
  turnBack.addEventListener('click', onTurnBack);
  turn.addEventListener('click', onTurn);
  mirror.addEventListener('click', onMirror);
  render();

  return {
    element,
    update(next, external) {
      board = posesOf(next);
      if (external) moving.clear();
      render();
    },
    showHint(action: Action) {
      if (action.type !== 'pose') return;
      const piece = byId.get(action.piece);
      if (piece === undefined) return;
      for (const other of pieces) other.group.classList.remove('is-hint');
      piece.group.classList.add('is-hint');
      ghost.setAttribute('d', tangramPath(tangramPolygon(action.piece, unit, action.x, action.y, action.rotation, action.flipped)));
      ghost.removeAttribute('display');
      select(piece);
      piece.group.focus();
      render();
      if (hintTimer !== null) clearTimeout(hintTimer);
      hintTimer = setTimeout(() => {
        piece.group.classList.remove('is-hint');
        ghost.setAttribute('display', 'none');
      }, 2500);
    },
    setPreview(visible) {
      if (visible) previewFrame.removeAttribute('display');
      else previewFrame.setAttribute('display', 'none');
    },
    focus() {
      const first = pieces.find((piece) => !placed.has(piece.index)) ?? pieces[0];
      first?.group.focus();
    },
    destroy() {
      if (hintTimer !== null) clearTimeout(hintTimer);
      image.cancel();
      stage.removeEventListener('keydown', onKeyDown);
      stage.removeEventListener('focusin', onFocusIn);
      stage.removeEventListener('pointerdown', onPointerDown);
      stage.removeEventListener('pointermove', onPointerMove);
      stage.removeEventListener('pointerup', onPointerEnd);
      stage.removeEventListener('pointercancel', onPointerEnd);
      turnBack.removeEventListener('click', onTurnBack);
      turn.removeEventListener('click', onTurn);
      mirror.removeEventListener('click', onMirror);
      element.remove();
    },
  };
}

function posesOf(value: Board): readonly TangramPose[] {
  if (!isTangramBoard(value)) throw new Error('Board does not match the tangram mode');
  return value.pieces;
}

function isTangramBoard(value: Board): value is TangramBoard {
  return 'pieces' in value && value.pieces.every((piece) => 'rotation' in piece);
}
