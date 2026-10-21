/**
 * Build the distributable plugin bundle and zip (org pattern).
 *
 * Mirrors code-snippets' scripts/bundle.ts: `npm run bundle` produces a clean
 * `bundle/` tree (built assets, no-dev vendor, PHP sources, languages, readme)
 * and a `translatewp.<version>.zip` beside it. CI (build.yml) runs this and
 * uploads `upload/translatewp` as the artifact; release.yml zips + publishes.
 */
import { spawnSync } from 'node:child_process'
import { cpSync, existsSync, mkdirSync, readdirSync, rmSync } from 'node:fs'
import { resolve } from 'node:path'
import process from 'node:process'

import plugin from '../package.json' with { type: 'json' }

const ROOT = resolve(import.meta.dirname, '..')
const SLUG = plugin.wpSlug ?? plugin.name.split('/').pop()
const DEST = resolve(ROOT, 'bundle', SLUG)

// Explicit include list (org convention): what ships is what is named here.
const BUNDLE_PATHS = [
	'translatewp.php',
	'uninstall.php',
	'index.php',
	'readme.txt',
	'LICENSE',
	'blocks',
	'build',
	'Components',
	'Entities',
	'Functionality',
	'Includes',
	'Utils',
	'languages',
	'vendor',
]

const run = (command, args, options = {}) => {
	const result = spawnSync(command, args, { cwd: ROOT, stdio: 'inherit', ...options })
	if (result.status !== 0) {
		console.error(`\n[bundle] ${command} ${args.join(' ')} failed (${result.status})`)
		process.exit(result.status ?? 1)
	}
}

run('node', ['scripts/check-domains.mjs'])

console.info('[bundle] building assets')
run('npm', ['run', 'build'])

console.info('[bundle] installing runtime vendor (no-dev)')
run('composer', ['install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--quiet'])

console.info('[bundle] staging bundle/')
rmSync(resolve(ROOT, 'bundle'), { recursive: true, force: true })
mkdirSync(DEST, { recursive: true })
for (const path of BUNDLE_PATHS) {
	const source = resolve(ROOT, path)
	if (!existsSync(source)) {
		console.error(`[bundle] missing required path: ${path}`)
		process.exit(1)
	}
	cpSync(source, resolve(DEST, path), { recursive: true })
}

// Composer leaves repository housekeeping inside vendor/, and a stale nested
// vendor/vendor/ can survive from an earlier install; none of it runs.
console.info('[bundle] pruning vendor housekeeping')
const PRUNE_NAMES = new Set(['.github', '.gitignore', '.gitattributes', '.editorconfig', '.travis.yml', 'phpunit.xml', 'phpunit.xml.dist'])
const prune = dir => {
	for (const entry of readdirSync(dir, { withFileTypes: true })) {
		const full = resolve(dir, entry.name)
		if (PRUNE_NAMES.has(entry.name)) rmSync(full, { recursive: true, force: true })
		else if (entry.isDirectory()) prune(full)
	}
}
prune(resolve(DEST, 'vendor'))
rmSync(resolve(DEST, 'vendor', 'vendor'), { recursive: true, force: true })
// Command-line entry points of dependencies (vendor/bin) are not used by the plugin.
rmSync(resolve(DEST, 'vendor', 'bin'), { recursive: true, force: true })

run('node', ['scripts/check-domains.mjs', '--root', DEST, '--shipped'])

console.info('[bundle] creating zip')
const zipName = `${SLUG}.${plugin.version}.zip`
rmSync(resolve(ROOT, zipName), { force: true })
run('zip', ['-qr', resolve(ROOT, zipName), SLUG], { cwd: resolve(ROOT, 'bundle') })

console.info('[bundle] restoring dev dependencies')
run('composer', ['install', '--quiet'])

console.info(`[bundle] done: ${zipName}`)
