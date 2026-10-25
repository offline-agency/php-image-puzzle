import { describe, expect, it, vi } from 'vitest';
import { PuzzleError, createEngine, definitionDocument as toDocument, parseDefinition } from '../src/index.js';
import type { EngineEventType } from '../src/index.js';
import { definitionDocument, manualClock } from './fixtures.js';

function recorder(engine: ReturnType<typeof createEngine>) {
  const events: string[] = [];
  for (const type of ['ready', 'move', 'state-change', 'progress', 'complete', 'error'] as EngineEventType[]) {
    engine.subscribe(type, (payload) => {
      const reason = (payload as { reason?: string }).reason;
      events.push(reason === undefined ? type : `${type}:${reason}`);
    });
  }
  return events;
}

describe('engine lifecycle and events', () => {
  it('emits ready after the state is in place, then ordered action events', () => {
    const engine = createEngine();
    const events = recorder(engine);
    engine.load(definitionDocument('strips'));
    expect(events).toEqual(['state-change:load', 'progress', 'ready']);
    events.length = 0;
    const hint = engine.hint();
    expect(hint).not.toBeNull();
    expect(events).toEqual(['state-change:hint', 'progress']);
    events.length = 0;
    expect(engine.dispatchAction(hint)).toEqual({ ok: true });
    expect(events).toEqual(['move', 'state-change:action', 'progress']);
    expect(engine.snapshot().hintsUsed).toBe(1);
  });

  it('completes, stops the timer and rejects further actions', () => {
    const clock = manualClock();
    const engine = createEngine({ definition: definitionDocument('swap'), clock: clock.now });
    const events = recorder(engine);
    let hint = engine.hint();
    while (hint !== null) {
      clock.advance(100);
      engine.dispatchAction(hint);
      hint = engine.hint();
    }
    expect(engine.status).toBe('complete');
    expect(events.slice(-4)).toEqual(['move', 'state-change:action', 'progress', 'complete']);
    const elapsed = engine.snapshot().elapsedMs;
    clock.advance(5000);
    expect(engine.snapshot().elapsedMs).toBe(elapsed);
    const result = engine.dispatchAction({ type: 'swap', a: 't0', b: 't1' });
    expect(result.ok).toBe(false);
    expect(engine.canUndo()).toBe(false);
    expect(engine.legalActions()).toEqual([]);
  });

  it('counts active time only and keeps it across export/import', () => {
    const clock = manualClock();
    const engine = createEngine({ definition: definitionDocument('rotation'), clock: clock.now });
    clock.advance(1000);
    expect(engine.snapshot().elapsedMs).toBe(0);
    engine.start();
    clock.advance(1500);
    engine.pause();
    clock.advance(60000);
    expect(engine.snapshot().elapsedMs).toBe(1500);
    const action = engine.legalActions()[0];
    engine.dispatchAction(action);
    expect(engine.status).toBe('running');
    clock.advance(500);
    const saved = engine.exportState();
    expect(saved.elapsedMs).toBe(2000);
    expect(saved.moves).toBe(1);

    const restored = createEngine({ definition: definitionDocument('rotation'), state: saved, clock: clock.now });
    expect(restored.status).toBe('idle');
    clock.advance(10000);
    expect(restored.snapshot().elapsedMs).toBe(2000);
    expect(restored.exportState().board).toEqual(saved.board);
  });

  it('undo and redo follow the PHP engine semantics', () => {
    const engine = createEngine({ definition: definitionDocument('sliding') });
    const initial = engine.exportState().board;
    const first = engine.legalActions()[0];
    engine.dispatchAction(first);
    const afterMove = engine.exportState().board;
    expect(engine.undo()).toBe(true);
    expect(engine.exportState().board).toEqual(initial);
    expect(engine.canRedo()).toBe(true);
    expect(engine.redo()).toBe(true);
    expect(engine.exportState().board).toEqual(afterMove);
    expect(engine.exportState().moves).toBe(3);
    engine.undo();
    engine.dispatchAction(engine.legalActions()[1]);
    expect(engine.canRedo()).toBe(false);
    expect(engine.redo()).toBe(false);
  });

  it('bounds the history', () => {
    const engine = createEngine({ definition: definitionDocument('rotation') });
    for (let i = 0; i < 130; i++) {
      engine.dispatchAction({ type: 'rotate', tile: 't5', direction: i % 2 === 0 ? 'cw' : 'ccw' });
    }
    expect(engine.exportState().history.past).toHaveLength(100);
  });

  it('reset and shuffle start fresh, shuffle is deterministic per seed', () => {
    const engine = createEngine({ definition: definitionDocument('swap') });
    engine.dispatchAction(engine.legalActions()[0]);
    engine.reset();
    const definition = engine.definition;
    expect(engine.exportState().board).toEqual(definition?.initial);
    expect(engine.exportState().moves).toBe(0);
    engine.shuffle(99);
    const once = engine.exportState().board;
    engine.shuffle(99);
    expect(engine.exportState().board).toEqual(once);
    const random = createEngine({ definition: definitionDocument('swap'), randomSeed: () => 99 });
    random.shuffle();
    expect(random.exportState().board).toEqual(once);
  });

  it('rejects malformed and illegal actions without changing state', () => {
    const engine = createEngine({ definition: definitionDocument('sliding') });
    const before = engine.exportState();
    for (const action of [null, 'slide', { type: 'slide' }, { type: 'slide', tile: 'zz' }, { type: 'swap', a: 't0', b: 't1' }]) {
      const result = engine.dispatchAction(action);
      expect(result.ok).toBe(false);
    }
    expect(engine.exportState()).toEqual(before);
    expect(engine.status).toBe('idle');
  });

  it('hides hints for modes without a hint capability', () => {
    const engine = createEngine({ definition: definitionDocument('sliding') });
    expect(engine.definition?.capabilities.hint).toBe(false);
    expect(engine.hint()).toBeNull();
    expect(engine.snapshot().hintsUsed).toBe(0);
  });
});

describe('atomic loading and importing', () => {
  it('a failed import or load leaves the current game untouched and reports an error', () => {
    // A frozen clock: the timer runs after the first action and must not affect the comparison.
    const engine = createEngine({ definition: definitionDocument('swap'), clock: manualClock().now });
    engine.dispatchAction(engine.legalActions()[0]);
    const before = engine.exportState();
    const errors: string[] = [];
    engine.subscribe('error', (payload) => errors.push(payload.code));

    const corrupt = { ...before, board: { cells: ['t0', 't0', 't2', 't3', 't4', 't5', 't6', 't7', 't8'] } };
    expect(() => {
      engine.importState(corrupt);
    }).toThrow(PuzzleError);
    expect(() => {
      engine.importState({ ...before, version: 2 });
    }).toThrow(/Unsupported game state version 2/);
    expect(() => {
      engine.importState({ ...before, definitionId: `gd1-${'0'.repeat(64)}` });
    }).toThrow(/another puzzle/);
    expect(() => {
      engine.importState({ ...before, completed: true });
    }).toThrow(/completed does not match/);
    expect(() => {
      engine.load({ ...definitionDocument('swap'), seed: 3 });
    }).toThrow(/does not match its content/);
    expect(() => {
      engine.load(definitionDocument('swap'), { ...before, moves: -1 });
    }).toThrow(PuzzleError);
    expect(engine.exportState()).toEqual(before);
    expect(errors).toEqual(['invalid', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid']);
  });

  it('switching puzzles replaces the session and emits ready again', () => {
    const engine = createEngine({ definition: definitionDocument('swap') });
    const ready = vi.fn();
    engine.subscribe('ready', ready);
    engine.load(definitionDocument('jigsaw'));
    expect(engine.definition?.kind).toBe('jigsaw');
    expect(ready).toHaveBeenCalledTimes(1);
  });

  it('definitions are frozen copies that survive mutation of the input', () => {
    const input = structuredClone(definitionDocument('strips'));
    const definition = parseDefinition(input);
    const [firstTile] = input['tiles'] as { id: string }[];
    if (firstTile === undefined) throw new Error('fixture has no tiles');
    firstTile.id = 'zz';
    expect(definition.tiles[0]?.id).toBe('s0');
    expect(Object.isFrozen(definition.tiles[0])).toBe(true);
    expect(toDocument(definition)['id']).toBe(definition.id);
  });
});

describe('subscriptions and destruction', () => {
  it('unsubscribe works during dispatch and a throwing listener does not break others', () => {
    const reported: unknown[] = [];
    const engine = createEngine({ definition: definitionDocument('swap'), onListenerError: (error) => reported.push(error) });
    const calls: string[] = [];
    const off = engine.subscribe('progress', () => {
      calls.push('first');
      off();
    });
    engine.subscribe('progress', () => {
      throw new Error('listener failure');
    });
    engine.subscribe('progress', () => calls.push('third'));
    engine.dispatchAction(engine.legalActions()[0]);
    engine.dispatchAction(engine.legalActions()[0]);
    expect(calls).toEqual(['first', 'third', 'third']);
    expect(reported).toHaveLength(2);
    expect(reported[0]).toBeInstanceOf(Error);
  });

  it('destroy releases listeners and blocks further use', () => {
    const engine = createEngine({ definition: definitionDocument('swap') });
    const listener = vi.fn();
    engine.subscribe('progress', listener);
    engine.destroy();
    engine.destroy();
    expect(engine.status).toBe('destroyed');
    expect(() => engine.dispatchAction({ type: 'swap', a: 't0', b: 't1' })).toThrow(/destroyed/);
    expect(() => engine.subscribe('progress', listener)).toThrow(/destroyed/);
    expect(listener).not.toHaveBeenCalled();
  });

  it('instances are independent', () => {
    const a = createEngine({ definition: definitionDocument('swap') });
    const b = createEngine({ definition: definitionDocument('swap') });
    a.dispatchAction(a.legalActions()[0]);
    expect(b.exportState().moves).toBe(0);
    expect(a.exportState().moves).toBe(1);
  });
});
