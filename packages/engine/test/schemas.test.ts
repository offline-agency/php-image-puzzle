import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import Ajv2020 from 'ajv/dist/2020.js';
import { describe, expect, it } from 'vitest';
import { createEngine, definitionDocument as toDocument } from '../src/index.js';
import type { EngineEventType } from '../src/index.js';
import { definitionDocument } from './fixtures.js';

/* The engine's exported documents and event payloads validate against the published schemas. */
const schemaDir = fileURLToPath(new URL('../../../docs/schemas/game/v1/', import.meta.url));
const ajv = new Ajv2020({ allErrors: true, strict: false });
for (const file of readdirSync(schemaDir)) {
  ajv.addSchema(JSON.parse(readFileSync(schemaDir + file, 'utf8')) as object);
}
const prefix = 'https://raw.githubusercontent.com/offline-agency/php-image-puzzle/HEAD/docs/schemas/game/v1/';

function check(schema: string, value: unknown): void {
  const validate = ajv.getSchema(prefix + schema);
  if (validate === undefined) throw new Error(`missing schema ${schema}`);
  const valid = validate(value);
  expect(valid ? [] : validate.errors).toEqual([]);
}

describe.each(['sliding', 'swap', 'rotation', 'strips', 'jigsaw', 'jigsaw-hex', 'nonogram', 'nonogram-ambiguous'])('%s', (name) => {
  it('definitions, states and every event payload match the schemas', () => {
    const engine = createEngine();
    const events: { type: string; detail: unknown }[] = [];
    for (const type of ['ready', 'move', 'state-change', 'progress', 'complete', 'error'] as EngineEventType[]) {
      engine.subscribe(type, (detail) => events.push({ type, detail }));
    }
    engine.load(definitionDocument(name));
    const definition = engine.definition;
    if (definition === null) throw new Error('not loaded');
    check('definition.schema.json', toDocument(definition));
    engine.start();
    for (let i = 0; i < 300 && engine.status !== 'complete'; i++) {
      engine.dispatchAction(engine.hint() ?? engine.legalActions()[0]);
      if (i === 2) {
        engine.undo();
        engine.redo();
        engine.pause();
      }
    }
    check('state.schema.json', engine.exportState());
    expect(() => {
      engine.importState({ broken: true });
    }).toThrow();
    for (const event of events) {
      check('events.schema.json', event);
    }
    expect(new Set(events.map((event) => event.type))).toEqual(
      new Set(
        name === 'sliding'
          ? ['ready', 'move', 'state-change', 'progress', 'error']
          : ['ready', 'move', 'state-change', 'progress', 'complete', 'error'],
      ),
    );
  });
});
