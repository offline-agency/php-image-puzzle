import * as doc from '../doc.js';
import { IllegalActionError, InvalidDocumentError } from '../errors.js';
import { Prng } from '../prng.js';
import type { Action } from '../types.js';
import { actionOf, permutation, stringList, tileIds } from './grid.js';
import type { ModeRules } from './types.js';

/**
 * Memory (src/Game/Rules/MemoryRules.php): find the two cards of every pair.
 * A mismatch stays face up until the next flip turns it face down; how long
 * a player shows it is a visual delay that never changes the state.
 */

const TYPES = ['flip'] as const;
export const MEMORY_MIN_SIDE = 2;
export const MEMORY_MAX_SIDE = 8;
export const MEMORY_MAX_TEXT_BYTES = 120;

/** The pair ID ("p3") of a card ID ("p3-a"). */
export function memoryPair(card: string): string {
  return card.slice(0, -2);
}

const partner = (card: string): string => memoryPair(card) + (card.endsWith('-a') ? '-b' : '-a');

/** A card label: 1-120 bytes without control characters or surrounding spaces. */
export function memoryText(value: unknown, path: string): string {
  const text = doc.stringValue(value, path, MEMORY_MAX_TEXT_BYTES);
  // PHP trim() only strips spaces and control characters; the latter are rejected anyway.
  if (text === '' || text.startsWith(' ') || text.endsWith(' ') || !/^[^\p{Cc}]+$/u.test(text)) {
    throw new InvalidDocumentError(
      `${path} must be 1-${MEMORY_MAX_TEXT_BYTES} bytes of text without control characters or surrounding spaces.`,
    );
  }
  return text;
}

function subset(value: unknown, ids: readonly string[], path: string, max: number): string[] {
  const out: string[] = [];
  doc.list(value, path, max).forEach((item, i) => {
    const id = doc.id(item, `${path}[${i}]`);
    if (!ids.includes(id)) {
      throw new InvalidDocumentError(`${path}[${i}] is an unknown card "${id}".`);
    }
    if (out.includes(id)) {
      throw new InvalidDocumentError(`${path}[${i}] repeats card "${id}".`);
    }
    out.push(id);
  });
  return out;
}

export const memoryRules: ModeRules = {
  kind: 'memory',
  actionTypes: TYPES,
  capabilities: { undo: false, redo: false, reset: true, shuffle: true, hint: false },

  validateDefinition(definition) {
    const { rows, cols, crop, tiles } = definition;
    if (rows < MEMORY_MIN_SIDE || cols < MEMORY_MIN_SIDE || rows > MEMORY_MAX_SIDE || cols > MEMORY_MAX_SIDE || (rows * cols) % 2 !== 0) {
      throw new InvalidDocumentError(
        `A memory board must be between ${MEMORY_MIN_SIDE}x${MEMORY_MIN_SIDE} and ${MEMORY_MAX_SIDE}x${MEMORY_MAX_SIDE} with an even number of cards, got ${rows}x${cols}.`,
      );
    }
    const pairs = (rows * cols) / 2;
    doc.keys(definition.rules, ['regions'], 'definition.rules');
    const regions = doc.object(definition.rules['regions'], 'definition.rules.regions');
    doc.keys(regions, ['rows', 'cols'], 'definition.rules.regions');
    const regionRows = doc.int(regions, 'rows', 'definition.rules.regions', 1, pairs);
    const regionCols = doc.int(regions, 'cols', 'definition.rules.regions', 1, pairs);
    if (regionRows * regionCols !== pairs) {
      throw new InvalidDocumentError(`definition.rules.regions must hold exactly ${pairs} regions.`);
    }
    if (crop.width % regionCols !== 0 || crop.height % regionRows !== 0) {
      throw new InvalidDocumentError('board.crop must divide into equal regions.');
    }
    if (tiles.length !== 2 * pairs) {
      throw new InvalidDocumentError(`A ${rows}x${cols} memory board needs exactly ${2 * pairs} cards, got ${tiles.length}.`);
    }
    const w = crop.width / regionCols;
    const h = crop.height / regionRows;
    tiles.forEach((tile, index) => {
      const pair = Math.floor(index / 2);
      const side = index % 2 === 0 ? 'a' : 'b';
      if (tile.id !== `p${pair}-${side}` || tile.home !== index) {
        throw new InvalidDocumentError(`Card ${index} must be "p${pair}-${side}" with home ${index} (cards are listed pair by pair).`);
      }
      const x = crop.x + (pair % regionCols) * w;
      const y = crop.y + Math.floor(pair / regionCols) * h;
      if (tile.rect.x !== x || tile.rect.y !== y || tile.rect.width !== w || tile.rect.height !== h) {
        throw new InvalidDocumentError(`Card ${tile.id} does not show region ${pair} of board.crop.`);
      }
      const path = `definition.tiles[${index}]`;
      const attributes = tile.attributes;
      if (attributes['pair'] !== `p${pair}`) {
        throw new InvalidDocumentError(`${path}.pair must be "p${pair}".`);
      }
      const face = attributes['face'];
      if (face === 'image') {
        doc.keys(attributes, ['pair', 'face'], path);
      } else if (face === 'text' && side === 'b') {
        doc.keys(attributes, ['pair', 'face', 'text'], path);
        memoryText(attributes['text'], `${path}.text`);
      } else {
        throw new InvalidDocumentError(
          `${path}.face must be "image"${side === 'b' ? ' or "text"' : ' (the first card of a pair shows the image)'}.`,
        );
      }
    });
  },

  solvedBoard(definition) {
    const ids = tileIds(definition);
    return { cards: ids, matched: [...ids], revealed: [] };
  },

  scramble(definition, seed) {
    return { cards: new Prng(seed, 'image-puzzle/scramble/memory').shuffle(tileIds(definition)), matched: [], revealed: [] };
  },

  validateBoard(definition, value) {
    const board = doc.object(value, 'board');
    doc.keys(board, ['cards', 'matched', 'revealed'], 'board');
    const ids = tileIds(definition);
    const cards = permutation(board['cards'], ids, 'board.cards');
    const matched = subset(board['matched'], ids, 'board.matched', ids.length);
    for (const id of matched) {
      if (!matched.includes(partner(id))) {
        throw new InvalidDocumentError(`board.matched has card ${id} without its partner.`);
      }
    }
    const revealed = subset(board['revealed'], ids, 'board.revealed', 2);
    for (const id of revealed) {
      if (matched.includes(id)) {
        throw new InvalidDocumentError(`board.revealed has the matched card ${id}.`);
      }
    }
    if (revealed.length === 2 && memoryPair(revealed[0] as string) === memoryPair(revealed[1] as string)) {
      throw new InvalidDocumentError('board.revealed holds a pair that should be matched.');
    }
    return { cards, matched, revealed };
  },

  apply(_definition, board, value) {
    const action = actionOf('memory', TYPES, value, ['card']);
    const card = doc.id(action['card'], 'action.card');
    const cards = stringList(board, 'cards');
    if (!cards.includes(card)) {
      throw new IllegalActionError(`Card "${card}" is not on the board.`);
    }
    let matched = stringList(board, 'matched');
    if (matched.includes(card)) {
      throw new IllegalActionError(`Card "${card}" is already matched.`);
    }
    let revealed = stringList(board, 'revealed');
    if (revealed.length === 2) {
      // A mismatch: the next flip turns both face down first.
      revealed = [];
    }
    if (revealed.includes(card)) {
      throw new IllegalActionError(`Card "${card}" is already face up.`);
    }
    revealed.push(card);
    if (revealed.length === 2 && memoryPair(revealed[0] as string) === memoryPair(revealed[1] as string)) {
      matched = [...matched, ...revealed];
      revealed = [];
    }
    return { cards, matched, revealed };
  },

  legalActions(_definition, board): Action[] {
    const matched = stringList(board, 'matched');
    const revealed = stringList(board, 'revealed');
    return stringList(board, 'cards')
      .filter((card) => !matched.includes(card) && !(revealed.length === 1 && revealed[0] === card))
      .map((card) => ({ type: 'flip', card }));
  },

  isComplete(definition, board) {
    return stringList(board, 'matched').length === definition.tiles.length;
  },

  progress(definition, board) {
    return stringList(board, 'matched').length / Math.max(1, definition.tiles.length);
  },

  /** No hints: they would reveal hidden cards. */
  hint() {
    return null;
  },
};
