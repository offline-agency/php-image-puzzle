import { cluesOf, nonogramCells, nonogramClues } from '@offline-agency/image-puzzle-engine';
import type { Action, Board, NonogramMark } from '@offline-agency/image-puzzle-engine';
import { el } from '../dom.js';
import { format } from '../messages.js';
import { backgroundFor, loadImage } from './media.js';
import type { ModeView, ViewContext } from './types.js';

const MARK_OF: Readonly<Record<string, NonogramMark>> = { '1': 'filled', x: 'crossed', '.': 'unknown' };

/**
 * DOM view of the nonogram: an ARIA grid whose column and row headers carry
 * the clues, one button per cell with roving focus. Enter/Space fills or
 * clears a cell, X crosses it, Delete/Backspace makes it unknown; a pointer
 * click applies the chosen tool (fill or cross; right-click or Shift+click
 * uses the other one). The picture is shown once the puzzle is complete.
 */
export function createNonogramView(ctx: ViewContext): ModeView {
  const { doc, definition } = ctx;
  const { rows, cols, crop, source } = definition;
  const clues = nonogramClues(definition);
  const imageUrl = ctx.asset(definition.media.image);
  const uid = Math.random().toString(36).slice(2, 10);

  const element = el(doc, 'div', { class: 'board-wrap nonogram-wrap' });
  const instructions = el(doc, 'p', { class: 'sr-only', id: `ipp-instructions-${uid}` });
  const tools = el(doc, 'div', { class: 'nonogram-tools', role: 'group' });
  const fillTool = el(doc, 'button', { type: 'button', class: 'control', 'data-tool': 'filled' });
  const crossTool = el(doc, 'button', { type: 'button', class: 'control', 'data-tool': 'crossed' });
  tools.append(fillTool, crossTool);

  const grid = el(doc, 'div', {
    class: 'nonogram',
    part: 'board',
    role: 'grid',
    'aria-describedby': instructions.id,
    'aria-rowcount': String(rows + 1),
    'aria-colcount': String(cols + 1),
    'data-kind': 'nonogram',
    'data-loading': 'true',
  });
  grid.style.setProperty('--ipp-cols', String(cols));
  grid.style.setProperty('--ipp-rows', String(rows));
  element.append(instructions, tools, grid);

  // Header row: an empty corner, then one column header per column clue.
  const headerRow = el(doc, 'div', { role: 'row', class: 'nonogram-row nonogram-head', 'aria-rowindex': '1' });
  headerRow.append(el(doc, 'div', { role: 'presentation', class: 'nonogram-corner' }));
  const colHeads: HTMLElement[] = [];
  clues.cols.forEach((clue, c) => {
    const head = el(doc, 'div', {
      role: 'columnheader',
      class: 'clue clue-col',
      'aria-colindex': String(c + 2),
      id: `ipp-col-${uid}-${c}`,
    });
    for (const run of clue.length > 0 ? clue : [0]) head.append(el(doc, 'span', { 'aria-hidden': 'true' }, String(run)));
    head.append(el(doc, 'span', { class: 'sr-only' }));
    // Explicit placement lets the picture overlay the cells without displacing them.
    head.style.gridArea = `1 / ${c + 2}`;
    colHeads.push(head);
    headerRow.append(head);
  });
  grid.append(headerRow);

  const rowHeads: HTMLElement[] = [];
  const buttons: HTMLButtonElement[] = [];
  const body = el(doc, 'div', { role: 'presentation', class: 'nonogram-body' });
  for (let r = 0; r < rows; r++) {
    const row = el(doc, 'div', { role: 'row', class: 'nonogram-row', 'aria-rowindex': String(r + 2) });
    const head = el(doc, 'div', { role: 'rowheader', class: 'clue clue-row', 'aria-colindex': '1', id: `ipp-row-${uid}-${r}` });
    for (const run of (clues.rows[r] ?? []).length > 0 ? (clues.rows[r] ?? []) : [0])
      head.append(el(doc, 'span', { 'aria-hidden': 'true' }, String(run)));
    head.append(el(doc, 'span', { class: 'sr-only' }));
    head.style.gridArea = `${r + 2} / 1`;
    rowHeads.push(head);
    row.append(head);
    for (let c = 0; c < cols; c++) {
      const cell = el(doc, 'div', { role: 'gridcell', class: 'nonogram-cell', 'aria-colindex': String(c + 2) });
      const button = el(doc, 'button', { type: 'button', class: 'cell', part: 'tile', tabindex: '-1', 'data-cell': String(r * cols + c) });
      cell.style.gridArea = `${r + 2} / ${c + 2}`;
      cell.append(button);
      buttons.push(button);
      row.append(cell);
    }
    body.append(row);
  }
  const picture = el(doc, 'div', { class: 'nonogram-picture', part: 'preview', hidden: '' });
  Object.assign(picture.style, backgroundFor(imageUrl, crop, source));
  body.append(picture);
  grid.append(body);

  const image = loadImage(
    imageUrl,
    () => {
      grid.setAttribute('data-loading', 'false');
    },
    () => {
      ctx.assetError(imageUrl);
    },
  );

  let cells = nonogramCells(ctx.engine.exportState().board);
  let focus = 0;
  let tool: 'filled' | 'crossed' = 'filled';
  let previewOn = false;
  let hintTimer: ReturnType<typeof setTimeout> | null = null;

  const markAt = (index: number): NonogramMark => MARK_OF[cells[index] ?? '.'] ?? 'unknown';
  const clueText = (clue: readonly number[]): string => (clue.length > 0 ? clue.join(' ') : '0');

  function render(): void {
    const m = ctx.messages();
    instructions.textContent = m.instructions['nonogram'] ?? '';
    tools.setAttribute('aria-label', m.nonogramTool);
    fillTool.textContent = m.nonogramFill;
    crossTool.textContent = m.nonogramCross;
    fillTool.setAttribute('aria-pressed', String(tool === 'filled'));
    crossTool.setAttribute('aria-pressed', String(tool === 'crossed'));
    grid.setAttribute('aria-label', m.label);
    const complete = ctx.engine.status === 'complete';
    const filled = cluesOf(cells.replace(/[x.]/g, '0'), rows, cols);
    const highlight = definition.assistance.highlightCorrect;
    colHeads.forEach((head, c) => {
      const done = highlight && clueText(filled.cols[c] ?? []) === clueText(clues.cols[c] ?? []);
      head.classList.toggle('is-done', done);
      (head.lastChild as HTMLElement).textContent = format(m.nonogramColumn, { n: c + 1, clue: clueText(clues.cols[c] ?? []) });
    });
    rowHeads.forEach((head, r) => {
      const done = highlight && clueText(filled.rows[r] ?? []) === clueText(clues.rows[r] ?? []);
      head.classList.toggle('is-done', done);
      (head.lastChild as HTMLElement).textContent = format(m.nonogramRow, { n: r + 1, clue: clueText(clues.rows[r] ?? []) });
    });
    buttons.forEach((button, i) => {
      const mark = markAt(i);
      button.dataset['state'] = mark;
      button.setAttribute(
        'aria-label',
        format(m.nonogramCell, { row: Math.floor(i / cols) + 1, col: (i % cols) + 1, state: m.nonogramStates[mark] ?? mark }),
      );
      button.tabIndex = i === focus ? 0 : -1;
      button.disabled = complete;
    });
    picture.hidden = !(complete || previewOn);
    grid.classList.toggle('is-complete', complete);
  }

  function mark(index: number, next: NonogramMark): void {
    if (ctx.engine.status === 'complete' || index < 0 || index >= buttons.length) return;
    const m = ctx.messages();
    if (markAt(index) === next) return;
    const ok = ctx.dispatch({ type: 'mark', cell: index, state: next });
    ctx.announce(
      ok
        ? format(m.nonogramCell, { row: Math.floor(index / cols) + 1, col: (index % cols) + 1, state: m.nonogramStates[next] ?? next })
        : m.illegal,
    );
  }

  /** Applies a tool: the same tool again clears the cell back to unknown. */
  function toggle(index: number, applied: 'filled' | 'crossed'): void {
    mark(index, markAt(index) === applied ? 'unknown' : applied);
  }

  function moveFocus(index: number): void {
    focus = Math.max(0, Math.min(buttons.length - 1, index));
    render();
    buttons[focus]?.focus();
  }

  function cellFrom(event: Event): number | null {
    const button = (event.target as Element | null)?.closest('button.cell');
    if (!(button instanceof HTMLElement) || !grid.contains(button)) return null;
    const index = Number(button.dataset['cell']);
    return Number.isInteger(index) ? index : null;
  }

  function onKeyDown(event: KeyboardEvent): void {
    const index = cellFrom(event);
    if (index === null) return;
    focus = index;
    const row = Math.floor(index / cols);
    const col = index % cols;
    const key = event.key;
    let next: number | null = null;
    if (key === 'ArrowUp' && row > 0) next = index - cols;
    else if (key === 'ArrowDown' && row < rows - 1) next = index + cols;
    else if (key === 'ArrowLeft' && col > 0) next = index - 1;
    else if (key === 'ArrowRight' && col < cols - 1) next = index + 1;
    else if (key === 'Home') next = event.ctrlKey ? 0 : row * cols;
    else if (key === 'End') next = event.ctrlKey ? buttons.length - 1 : row * cols + cols - 1;
    if (next !== null || key.startsWith('Arrow')) {
      event.preventDefault();
      if (next !== null) moveFocus(next);
      return;
    }
    if (key === 'Enter' || key === ' ') {
      event.preventDefault();
      toggle(index, 'filled');
    } else if (key === 'x' || key === 'X') {
      event.preventDefault();
      toggle(index, 'crossed');
    } else if (key === 'Delete' || key === 'Backspace') {
      event.preventDefault();
      mark(index, 'unknown');
    }
  }

  function onClick(event: MouseEvent): void {
    const index = cellFrom(event);
    if (index === null) return;
    focus = index;
    // Keyboard activation is handled in onKeyDown (detail 0 = synthetic click from Enter/Space).
    if (event.detail === 0) return;
    toggle(index, event.shiftKey ? (tool === 'filled' ? 'crossed' : 'filled') : tool);
  }

  function onContextMenu(event: MouseEvent): void {
    const index = cellFrom(event);
    if (index === null) return;
    event.preventDefault();
    focus = index;
    toggle(index, tool === 'filled' ? 'crossed' : 'filled');
  }

  const chooseFill = (): void => {
    tool = 'filled';
    render();
  };
  const chooseCross = (): void => {
    tool = 'crossed';
    render();
  };
  fillTool.addEventListener('click', chooseFill);
  crossTool.addEventListener('click', chooseCross);
  grid.addEventListener('keydown', onKeyDown);
  grid.addEventListener('click', onClick);
  grid.addEventListener('contextmenu', onContextMenu);
  render();

  return {
    element,
    update(next: Board) {
      cells = nonogramCells(next);
      render();
    },
    showHint(action: Action) {
      if (action.type !== 'mark') return;
      const button = buttons[action.cell];
      if (button === undefined) return;
      for (const other of buttons) other.classList.remove('is-hint');
      button.classList.add('is-hint');
      if (hintTimer !== null) clearTimeout(hintTimer);
      hintTimer = setTimeout(() => {
        button.classList.remove('is-hint');
      }, 2500);
      moveFocus(action.cell);
      const m = ctx.messages();
      ctx.announce(
        format(m.hintGiven, {
          hint: format(m.nonogramCell, {
            row: Math.floor(action.cell / cols) + 1,
            col: (action.cell % cols) + 1,
            state: m.nonogramStates[action.state] ?? action.state,
          }),
        }),
      );
    },
    setPreview(visible) {
      previewOn = visible;
      render();
    },
    focus() {
      buttons[focus]?.focus();
    },
    destroy() {
      if (hintTimer !== null) clearTimeout(hintTimer);
      image.cancel();
      fillTool.removeEventListener('click', chooseFill);
      crossTool.removeEventListener('click', chooseCross);
      grid.removeEventListener('keydown', onKeyDown);
      grid.removeEventListener('click', onClick);
      grid.removeEventListener('contextmenu', onContextMenu);
      element.remove();
    },
  };
}
