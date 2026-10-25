import * as doc from './doc.js';
import type { Rect } from './types.js';

const LIMIT = 1 << 20;

export function parseRect(value: unknown, path: string): Rect {
  const rect = doc.object(value, path);
  doc.keys(rect, ['x', 'y', 'width', 'height'], path);
  return {
    x: doc.int(rect, 'x', path, -LIMIT, LIMIT),
    y: doc.int(rect, 'y', path, -LIMIT, LIMIT),
    width: doc.int(rect, 'width', path, 1, LIMIT),
    height: doc.int(rect, 'height', path, 1, LIMIT),
  };
}
