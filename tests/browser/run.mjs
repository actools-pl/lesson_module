import {createServer} from 'node:http';
import {createReadStream} from 'node:fs';
import {mkdir, mkdtemp, realpath, stat, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {dirname, extname, join, resolve, sep} from 'node:path';
import {fileURLToPath} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const moduleRoot = await realpath(resolve(here, '../..'));
const outputDir = process.env.ACTOOLS_TEST_BROWSER_OUTPUT
  ? resolve(process.env.ACTOOLS_TEST_BROWSER_OUTPUT)
  : await mkdtemp(join(tmpdir(), 'actools-lesson-browser.'));
await mkdir(outputDir, {recursive: true});
const summary = {status: 'failed', moduleRoot, startedAt: new Date().toISOString()};
let server;
let browser;

try {
  if (!process.env.DRUPAL_ROOT) {
    throw new Error('Set DRUPAL_ROOT to the disposable Drupal webroot containing core/.');
  }
  const drupalRoot = await realpath(process.env.DRUPAL_ROOT);
  await stat(join(drupalRoot, 'core/misc/drupal.js'));
  await stat(join(drupalRoot, 'core/assets/vendor/once/once.min.js'));
  const roots = [
    ['/module/', moduleRoot],
    ['/core/', await realpath(join(drupalRoot, 'core'))],
    ['/fixtures/', await realpath(join(here, 'fixtures'))],
  ];
  const mime = {
    '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
    '.mjs': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8',
    '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png',
    '.jpg': 'image/jpeg', '.webp': 'image/webp', '.woff': 'font/woff',
    '.woff2': 'font/woff2', '.txt': 'text/plain; charset=utf-8',
  };
  server = createServer(async (request, response) => {
    try {
      if (!['GET', 'HEAD'].includes(request.method)) {
        response.writeHead(405).end();
        return;
      }
      const pathname = decodeURIComponent(new URL(request.url, 'http://localhost').pathname);
      const route = roots.find(([prefix]) => pathname.startsWith(prefix));
      if (!route) {
        response.writeHead(404).end();
        return;
      }
      const [prefix, root] = route;
      const candidate = await realpath(resolve(root, pathname.slice(prefix.length)));
      if (!candidate.startsWith(root + sep) || !(await stat(candidate)).isFile()) {
        response.writeHead(404).end();
        return;
      }
      response.writeHead(200, {
        'Content-Type': mime[extname(candidate).toLowerCase()] || 'application/octet-stream',
        'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff',
      });
      if (request.method === 'HEAD') {
        response.end();
      }
      else {
        const stream = createReadStream(candidate);
        stream.on('error', () => response.destroy());
        stream.pipe(response);
      }
    }
    catch {
      response.writeHead(404).end();
    }
  });
  await new Promise((resolveListen, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolveListen);
  });
  const args = JSON.parse(process.env.ACTOOLS_TEST_CHROMIUM_ARGS || '[]');
  if (!Array.isArray(args) || !args.every(value => typeof value === 'string')) {
    throw new Error('ACTOOLS_TEST_CHROMIUM_ARGS must be a JSON array of strings.');
  }
  const {chromium} = await import(process.env.ACTOOLS_TEST_PLAYWRIGHT_MODULE || 'playwright');
  browser = await chromium.launch({
    headless: true, args,
    ...(process.env.ACTOOLS_TEST_CHROMIUM ? {executablePath: process.env.ACTOOLS_TEST_CHROMIUM} : {}),
  });
  const {runRegressions} = await import('./regressions.mjs');
  summary.browser = browser.version();
  summary.drupalRoot = drupalRoot;
  summary.checks = await runRegressions({
    browser, baseURL: `http://127.0.0.1:${server.address().port}`,
    moduleRoot, drupalRoot, outputDir,
  });
  summary.status = 'passed';
}
catch (error) {
  summary.error = error.stack || String(error);
  process.exitCode = 1;
}
finally {
  if (browser) {
    try {
      await browser.close();
    }
    catch (error) {
      summary.status = 'failed';
      summary.error ||= error.stack || String(error);
      process.exitCode = 1;
    }
  }
  if (server) {
    server.closeAllConnections();
    await new Promise(resolveClose => server.close(resolveClose));
  }
  summary.finishedAt = new Date().toISOString();
  await writeFile(join(outputDir, 'summary.json'), JSON.stringify(summary, null, 2) + '\n');
  console.log(JSON.stringify({status: summary.status, summary: join(outputDir, 'summary.json')}));
  if (summary.error) {
    console.error(summary.error);
  }
}
