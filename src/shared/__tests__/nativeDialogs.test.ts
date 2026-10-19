import { readdirSync, readFileSync } from 'fs';
import { join, resolve, sep } from 'path';

const SRC_DIR = resolve(__dirname, '..', '..');

const SOURCE_EXTENSIONS = ['.ts', '.tsx', '.js', '.jsx'];

const isTestFile = (path: string): boolean =>
    path.includes(`${sep}__tests__${sep}`) || /\.(test|spec)\.[jt]sx?$/.test(path);

const collectSourceFiles = (directory: string): string[] =>
    readdirSync(directory, { withFileTypes: true }).flatMap((entry) =>
    {
        const fullPath = join(directory, entry.name);

        if (entry.isDirectory())
        {
            return collectSourceFiles(fullPath);
        }

        const isSource = SOURCE_EXTENSIONS.some((extension) => entry.name.endsWith(extension));

        return isSource && !isTestFile(fullPath) ? [fullPath] : [];
    });

const NATIVE_DIALOG_SURFACES: { label: string; pattern: RegExp }[] = [
    { label: 'window.confirm', pattern: /window\s*\.\s*confirm\b/ },
    { label: 'window.alert', pattern: /window\s*\.\s*alert\b/ },
    { label: 'window.prompt', pattern: /window\s*\.\s*prompt\b/ },
    { label: 'confirm()', pattern: /(^|[^\w.])confirm\s*\(/m },
    { label: 'alert()', pattern: /(^|[^\w.])alert\s*\(/m },
    { label: 'prompt()', pattern: /(^|[^\w.])prompt\s*\(/m },
    {
        label: 'Notification API',
        pattern: /\bnew\s+Notification\b|\bNotification\s*\.\s*(requestPermission|permission)\b|window\s*\.\s*Notification\b/,
    },
];

describe('product code avoids native browser dialogs', () =>
{
    const sourceFiles = collectSourceFiles(SRC_DIR);

    it('has product source files to scan', () =>
    {
        expect(sourceFiles.length).toBeGreaterThan(0);
    });

    it.each(NATIVE_DIALOG_SURFACES)('never uses $label in product code', ({ pattern }) =>
    {
        const offenders = sourceFiles.filter((file) => pattern.test(readFileSync(file, 'utf8')));

        expect(offenders).toEqual([]);
    });
});
