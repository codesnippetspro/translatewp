import { spawnSync } from 'node:child_process';
import { lstatSync, readFileSync, readdirSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';

const args = process.argv.slice(2);
const shipped = args.includes('--shipped');
const rootIndex = args.indexOf('--root');
const root = resolve(rootIndex < 0 ? '.' : args[rootIndex + 1]);
const host = (prefix, suffix) => `${prefix ? `${prefix}.` : ''}translatewp.${suffix}`;
const allowed = new Set([
    ...['', 'www', 'app', 'api', 'admin', 'analytics'].map(prefix => host(prefix, 'ai')),
    ...((shipped || args.includes('--public')) ? [] : ['', 'app', 'api', 'admin'].map(prefix => host(prefix, 'zip'))),
]);
const fileExtensions = new Set(['php', 'js', 'css', 'json', 'tsx', 'ts', 'pot', 'asset']);
const codeSymbol = /^(?:(?:[a-z0-9_$]+\.)+TranslateWP\.[a-zA-Z0-9_.]+|TranslateWP\.(?:options|context|frontend|settingsUrl|canManage|can)(?:\.[a-zA-Z0-9_]+)*|translatewp\.(?:(?:frontend\.)?slot(?:\.[a-zA-Z0-9-]+)*|frontend\.slotsChanged|admin\.pricingScheduler|languages|inUse|status))$/;
const normalize = text => text.replace(/\0/g, '').replace(/\\u([a-f0-9]{4})|\\x([a-f0-9]{2})/gi, (_, unicode, hex) => String.fromCharCode(parseInt(unicode || hex, 16)))
    .replace(/(?:%[a-f0-9]{2})+/gi, encoded => {
        try { return decodeURIComponent(encoded); } catch { return encoded; }
    });
const pattern = /(?<![a-z0-9_.-])(?:[a-z0-9-]+\.)*translate-?wp\.([a-z][a-z0-9-]*)(?:\.[a-z0-9-]+)*(?![a-z0-9_-])/gi;
let files;
if (shipped) {
    const walk = directory => readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
        const path = join(directory, entry.name);
        return entry.isDirectory() ? walk(path) : [relative(root, path)];
    });
    files = walk(root);
} else {
    const result = spawnSync('git', ['-C', root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], { encoding: 'utf8' });
    if (result.status !== 0) throw new Error('Cannot select repository files');
    files = [...new Set(result.stdout.split('\0').filter(Boolean))];
}
let failures = 0;
for (const file of files) {
    const path = join(root, file);
    if (lstatSync(path).isSymbolicLink()) throw new Error(`Cannot inspect symlink: ${file}`);
    const bytes = readFileSync(path);
    const text = normalize(bytes.toString('utf8'));
    text.split('\n').forEach((line, index) => {
        if (shipped && /\.(?:up\.railway\.app|trycloudflare\.com|ngrok(?:-free)?\.(?:app|io))\b/i.test(line)) {
            console.error(`${file}:${index + 1}: internal service host in customer package`);
            failures += 1;
        }
        for (const match of line.matchAll(pattern)) {
            const suffix = match[1].toLowerCase();
            const url = /(?:https?:)?\/\/$/i.test(line.slice(0, match.index));
            if (!url && (fileExtensions.has(suffix) || codeSymbol.test(match[0]))) continue;
            if (!allowed.has(match[0].toLowerCase())) {
                console.error(`${file}:${index + 1}: unapproved product host`);
                failures += 1;
            }
        }
    });
    if (/^(?:translatewp|translatewp-pro)\.php$/.test(file)) {
        for (const name of ['Plugin URI', 'Author URI']) {
            const value = new RegExp(`^ \\* ${name}:\\s*(\\S+)`, 'm').exec(text)?.[1];
            if (value !== 'https://translatewp.ai/') {
                console.error(`${file}: incorrect ${name}`);
                failures += 1;
            }
        }
    }
}
if (failures) process.exitCode = 1;
else console.log(`Domain check passed (${files.length} files${shipped ? ', customer package' : ''})`);
