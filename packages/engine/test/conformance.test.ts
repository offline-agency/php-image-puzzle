import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { IllegalActionError, InvalidDocumentError, Prng, fnv1a, parseDefinition, rulesFor } from '../src/index.js';
import type { Board } from '../src/index.js';

/*
 * Golden fixtures generated from the PHP rules (composer conformance). The
 * TypeScript engine must reproduce every value exactly.
 */
const dir = fileURLToPath(new URL('../../../conformance/', import.meta.url));
const read = (name: string): unknown => JSON.parse(readFileSync(dir + name, 'utf8'));

interface PrngVector {
  seed: number;
  stream: string;
  fnv1a: number;
  uint32: number[];
  int: { n: number; value: number }[];
  shuffle: number[];
}

interface Step {
  action: unknown;
  result: 'ok' | 'illegal' | 'invalid';
  board?: Board;
  complete?: boolean;
  progress?: number;
  hint?: unknown;
}

interface ModeFixture {
  name: string;
  definition: unknown;
  solved: Board;
  scrambles: { seed: number; board: Board }[];
  legalActions: unknown[];
  cases: { name: string; board: Board; steps: Step[] }[];
  imports: { name: string; board: unknown; valid: boolean }[];
}

describe('PRNG', () => {
  const { vectors } = read('prng.json') as { vectors: PrngVector[] };
  it.each(vectors)('matches PHP for seed $seed / "$stream"', (vector) => {
    expect(fnv1a(vector.stream)).toBe(vector.fnv1a);
    const rng = new Prng(vector.seed, vector.stream);
    expect(Array.from({ length: vector.uint32.length }, () => rng.nextUint32())).toEqual(vector.uint32);
    expect(vector.int.map(({ n }) => ({ n, value: rng.int(n) }))).toEqual(vector.int);
    expect(rng.shuffle(Array.from({ length: 10 }, (_, i) => i))).toEqual(vector.shuffle);
  });
});

const modeFiles = readdirSync(dir).filter((name) => name.startsWith('mode-'));

describe.each(modeFiles)('%s', (file) => {
  const fixture = read(file) as ModeFixture;
  const definition = parseDefinition(fixture.definition);
  const rules = rulesFor(definition.kind);

  it('accepts the PHP definition and verifies its id', () => {
    expect(definition.id).toBe((fixture.definition as { id: string }).id);
  });

  it('produces the same solved board and scrambles per seed', () => {
    expect(rules.solvedBoard(definition)).toEqual(fixture.solved);
    for (const { seed, board } of fixture.scrambles) {
      expect(rules.scramble(definition, seed)).toEqual(board);
    }
  });

  it('lists the same legal actions in the same order', () => {
    expect(rules.legalActions(definition, definition.initial)).toEqual(fixture.legalActions);
  });

  it.each(fixture.cases.map((testCase) => [testCase.name, testCase] as const))('replays "%s"', (_, testCase) => {
    let board = testCase.board;
    for (const step of testCase.steps) {
      let next: Board | null = null;
      let result: Step['result'] = 'ok';
      try {
        next = rules.apply(definition, board, step.action);
      } catch (error) {
        if (error instanceof IllegalActionError) result = 'illegal';
        else if (error instanceof InvalidDocumentError) result = 'invalid';
        else throw error;
      }
      expect({ action: step.action, result }).toEqual({ action: step.action, result: step.result });
      if (next !== null) {
        expect(next).toEqual(step.board);
        expect(rules.isComplete(definition, next)).toBe(step.complete);
        expect(rules.progress(definition, next)).toBe(step.progress);
        expect(rules.hint(definition, next)).toEqual(step.hint);
        board = next;
      }
    }
  });

  it('accepts and rejects the same imported boards', () => {
    for (const entry of fixture.imports) {
      let valid = true;
      try {
        rules.validateBoard(definition, entry.board);
      } catch (error) {
        if (!(error instanceof InvalidDocumentError)) throw error;
        valid = false;
      }
      expect({ name: entry.name, valid }).toEqual({ name: entry.name, valid: entry.valid });
    }
  });
});
