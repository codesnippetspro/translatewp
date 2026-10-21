import { spawnSync } from 'node:child_process';
import { lstatSync, readFileSync, readdirSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';

const args = process.argv.slice(2);
const shipped = args.includes('--shipped');
const rootIndex = args.indexOf('--root');
const root = resolve(rootIndex < 0 ? '.' : args[rootIndex + 1]);
const allowed = new Set(shipped ? ['ai'] : ['ai', 'zip']);
const nonHosts = new Set(['php', 'js', 'css', 'json', 'tsx', 'ts', 'pot', 'asset', 'slot', 'options', 'context', 'settingsurl', 'canmanage', 'frontend', 'host', 'can', 'admin', 'languages', 'inuse', 'status']);
const pattern = /(?<![a-z0-9_.-])(?:[a-z0-9-]+\.)*translatewp\.([a-z][a-z0-9-]*)(?:\.[a-z0-9-]+)*(?![a-z0-9_-])/gi;
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
    if (bytes.includes(0)) continue;
    const text = bytes.toString('utf8');
    text.split('\n').forEach((line, index) => {
        for (const match of line.matchAll(pattern)) {
            const suffix = match[1].toLowerCase();
            const url = /(?:https?:)?\/\/$/i.test(line.slice(0, match.index));
            if (!url && nonHosts.has(suffix)) continue;
            if (!allowed.has(suffix) || !/^(?:[a-z0-9-]+\.)*translatewp\.(?:ai|zip)$/i.test(match[0])) {
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
