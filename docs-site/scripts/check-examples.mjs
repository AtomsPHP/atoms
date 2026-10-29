import { readdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

export function checkExamples(markdown, filename = '<input>') {
    const errors = [];
    const lines = markdown.split(/\r?\n/);
    let fence;
    for (let i = 0; i < lines.length; i++) {
        if (!fence) {
            const opening = lines[i].match(/^ {0,3}(`{3,}|~{3,})(.*)$/);
            if (!opening) continue;
            const info = opening[2].trim();
            const language = info.match(/^\S+/)?.[0] ?? '';
            const title = info.match(/(?:^|\s)title=(?:"([^"]*)"|'([^']*)'|([^\s]+))/);
            const jsonTitle = (title?.[1] ?? title?.[2] ?? title?.[3] ?? '').endsWith('.json');
            fence = { marker: opening[1], language, jsonTitle, line: i + 1, content: [] };
            continue;
        }
        const closing = lines[i].match(/^ {0,3}(`+|~+)\s*$/);
        if (closing && closing[1][0] === fence.marker[0] && closing[1].length >= fence.marker.length) {
            const location = `${filename}:${fence.line}`;
            if (fence.jsonTitle && fence.language !== 'json') {
                errors.push(`${location}: a .json file example must use a json fence`);
            }
            if (fence.language === 'json' || fence.jsonTitle) {
                try {
                    JSON.parse(fence.content.join('\n'));
                } catch (error) {
                    errors.push(`${location}: invalid JSON: ${error.message}`);
                }
            }
            fence = undefined;
        } else {
            fence.content.push(lines[i]);
        }
    }
    if (fence && (fence.language === 'json' || fence.jsonTitle)) {
        errors.push(`${filename}:${fence.line}: unclosed JSON example`);
    }
    return errors;
}

async function markdownFiles(directory) {
    const entries = await readdir(directory, { withFileTypes: true });
    const files = await Promise.all(entries.map(async (entry) => {
        const name = path.join(directory, entry.name);
        if (entry.isDirectory()) return markdownFiles(name);
        return entry.isFile() && /\.mdx?$/.test(entry.name) ? [name] : [];
    }));
    return files.flat().sort();
}

async function main() {
    const root = fileURLToPath(new URL('../src/content/docs/', import.meta.url));
    const files = await markdownFiles(root);
    const errors = (await Promise.all(files.map(async (file) =>
        checkExamples(await readFile(file, 'utf8'), path.relative(root, file))))).flat();
    if (errors.length) {
        console.error(errors.join('\n'));
        process.exitCode = 1;
    } else {
        console.log(`Checked JSON examples in ${files.length} Markdown files.`);
    }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    await main();
}
