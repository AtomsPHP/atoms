import { access, readFile, readdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const dist = path.resolve(here, '../dist');
const catalogPath = path.resolve(here, '../../packages/core/resources/errors.json');

async function walk(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const resolved = path.join(directory, entry.name);
    if (entry.isDirectory()) files.push(...await walk(resolved));
    else if (entry.name.endsWith('.html')) files.push(resolved);
  }
  return files;
}

function targetFile(hrefPath) {
  const clean = decodeURIComponent(hrefPath).replace(/^\//, '');
  if (clean === '') return path.join(dist, 'index.html');
  if (path.extname(clean) !== '') return path.join(dist, clean);
  return path.join(dist, clean.replace(/\/$/, ''), 'index.html');
}

const htmlFiles = await walk(dist);
const failures = [];
for (const file of htmlFiles) {
  const html = await readFile(file, 'utf8');
  for (const match of html.matchAll(/href="([^"]+)"/g)) {
    const href = match[1];
    if (/^(?:https?:|mailto:|tel:|javascript:)/.test(href)) continue;
    const [hrefPath, fragment] = href.split('#');
    const destination = hrefPath
      ? targetFile(hrefPath.startsWith('/') ? hrefPath : '/' + path.posix.normalize(path.posix.join(path.relative(dist, path.dirname(file)).replaceAll(path.sep, '/'), hrefPath)))
      : file;
    const exists = await access(destination).then(() => true).catch(() => false);
    if (!exists) {
      failures.push(`${path.relative(dist, file)} -> missing ${href}`);
      continue;
    }
    const destinationHtml = fragment ? await readFile(destination, 'utf8') : '';
    if (fragment && !destinationHtml.includes(`id="${decodeURIComponent(fragment)}"`)) {
      failures.push(`${path.relative(dist, file)} -> missing anchor ${href}`);
    }
  }
}

const errorsHtml = await readFile(path.join(dist, 'reference/errors/index.html'), 'utf8');
const catalog = JSON.parse(await readFile(catalogPath, 'utf8'));
for (const entry of catalog.errors) {
  if (!errorsHtml.includes(`id="${entry.code.toLowerCase()}"`)) {
    failures.push(`Error catalog is missing #${entry.code.toLowerCase()}`);
  }
}

if (failures.length) throw new Error(`Site validation failed:\n${failures.join('\n')}`);
console.log(`Validated ${htmlFiles.length} pages and ${catalog.errors.length} stable error anchors.`);
