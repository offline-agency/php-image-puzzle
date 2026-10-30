// Static file server for the browser tests: serves the repository root on
// 127.0.0.1 so examples and dist builds load exactly as a consumer's page would.
import { createReadStream, statSync } from 'node:fs';
import { createServer } from 'node:http';
import { extname, join, normalize, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const port = Number(process.env.PORT ?? 4173);
const types = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json',
  '.png': 'image/png',
  '.webp': 'image/webp',
  '.svg': 'image/svg+xml',
  '.css': 'text/css; charset=utf-8',
  '.map': 'application/json',
};

createServer((request, response) => {
  const url = new URL(request.url ?? '/', 'http://localhost');
  const path = normalize(join(root, decodeURIComponent(url.pathname)));
  if (!path.startsWith(root) || path.split(sep).includes('node_modules')) {
    response.writeHead(403).end();
    return;
  }
  let stat;
  try {
    stat = statSync(path);
  } catch {
    response.writeHead(404).end('not found');
    return;
  }
  if (!stat.isFile()) {
    response.writeHead(404).end('not found');
    return;
  }
  response.writeHead(200, { 'content-type': types[extname(path)] ?? 'application/octet-stream', 'cache-control': 'no-store' });
  createReadStream(path).pipe(response);
}).listen(port, '127.0.0.1', () => {
  console.log(`serving ${root} on http://127.0.0.1:${port}`);
});
