import { PuzzleError } from '../errors.js';
import type { GameKind } from '../types.js';
import { jigsawRules } from './jigsaw.js';
import { memoryRules } from './memory.js';
import { nonogramRules } from './nonogram.js';
import { rotationRules } from './rotation.js';
import { slidingRules } from './sliding.js';
import { stripsRules } from './strips.js';
import { swapRules } from './swap.js';
import { tangramRules } from './tangram.js';
import type { ModeRules } from './types.js';

const RULES: Partial<Record<GameKind, ModeRules>> = {
  sliding: slidingRules,
  swap: swapRules,
  rotation: rotationRules,
  strips: stripsRules,
  jigsaw: jigsawRules,
  nonogram: nonogramRules,
  memory: memoryRules,
  tangram: tangramRules,
};

/** Rules for a kind, or an `unsupported` error for kinds this version does not ship. */
export function rulesFor(kind: GameKind): ModeRules {
  const rules = RULES[kind];
  if (rules === undefined) {
    throw new PuzzleError('unsupported', `The ${kind} mode is not available in this version.`);
  }
  return rules;
}

export function availableKinds(): GameKind[] {
  return Object.keys(RULES) as GameKind[];
}

export type { DefinitionCore, ModeRules } from './types.js';
