import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const dir = fileURLToPath(new URL('../../../conformance/', import.meta.url));

/** A PHP-generated definition document from the conformance fixtures. */
export function definitionDocument(name: string): Record<string, unknown> {
  const fixture = JSON.parse(readFileSync(`${dir}mode-${name}.json`, 'utf8')) as { definition: Record<string, unknown> };
  return fixture.definition;
}

/** A controllable clock for timer tests. */
export function manualClock(start = 1000): { now: () => number; advance: (ms: number) => void } {
  let time = start;
  return {
    now: () => time,
    advance: (ms: number) => {
      time += ms;
    },
  };
}
