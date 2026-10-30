import { inflateSync } from 'node:zlib';

export interface Pixels {
  readonly width: number;
  readonly height: number;
  /** RGBA, row by row. */
  readonly data: Uint8Array;
}

/** Decodes the 8-bit RGB/RGBA, non-interlaced PNGs that Playwright screenshots produce. */
export function decodePng(png: Buffer): Pixels {
  let offset = 8;
  let width = 0;
  let height = 0;
  let channels = 0;
  const chunks: Buffer[] = [];
  while (offset < png.length) {
    const length = png.readUInt32BE(offset);
    const type = png.toString('latin1', offset + 4, offset + 8);
    const body = png.subarray(offset + 8, offset + 8 + length);
    if (type === 'IHDR') {
      width = body.readUInt32BE(0);
      height = body.readUInt32BE(4);
      const [depth, color, , , interlace] = [body[8], body[9], body[10], body[11], body[12]];
      if (depth !== 8 || (color !== 2 && color !== 6) || interlace !== 0) throw new Error('Unsupported PNG layout.');
      channels = color === 6 ? 4 : 3;
    } else if (type === 'IDAT') {
      chunks.push(body);
    }
    offset += 12 + length;
  }
  const raw = inflateSync(Buffer.concat(chunks));
  const stride = width * channels;
  const out = new Uint8Array(width * height * 4);
  let previous = new Uint8Array(stride);
  for (let y = 0; y < height; y++) {
    const filter = raw[y * (stride + 1)];
    const line = raw.subarray(y * (stride + 1) + 1, (y + 1) * (stride + 1));
    const current = new Uint8Array(stride);
    for (let i = 0; i < stride; i++) {
      const a = i >= channels ? (current[i - channels] ?? 0) : 0;
      const b = previous[i] ?? 0;
      const c = i >= channels ? (previous[i - channels] ?? 0) : 0;
      const x = line[i] ?? 0;
      let value: number;
      switch (filter) {
        case 0:
          value = x;
          break;
        case 1:
          value = x + a;
          break;
        case 2:
          value = x + b;
          break;
        case 3:
          value = x + ((a + b) >> 1);
          break;
        case 4: {
          const p = a + b - c;
          const pa = Math.abs(p - a);
          const pb = Math.abs(p - b);
          const pc = Math.abs(p - c);
          value = x + (pa <= pb && pa <= pc ? a : pb <= pc ? b : c);
          break;
        }
        default:
          throw new Error(`Unknown PNG filter ${String(filter)}.`);
      }
      current[i] = value & 0xff;
    }
    for (let px = 0; px < width; px++) {
      const target = (y * width + px) * 4;
      out[target] = current[px * channels] ?? 0;
      out[target + 1] = current[px * channels + 1] ?? 0;
      out[target + 2] = current[px * channels + 2] ?? 0;
      out[target + 3] = channels === 4 ? (current[px * channels + 3] ?? 0) : 255;
    }
    previous = current;
  }
  return { width, height, data: out };
}
