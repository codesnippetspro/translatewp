#!/usr/bin/env node
import { spawnSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { existsSync, lstatSync, readFileSync, readdirSync, realpathSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const SCRIPT_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const MODES = new Set(['tracked', 'tree', 'package'])
const TOKEN = new RegExp([
	/gh[pousr]_[a-zA-Z0-9]{30,}/.source,
	/github_pat_[a-zA-Z0-9_]{30,}/.source,
	/sk-(?:proj-)?[a-zA-Z0-9_-]{24,}/.source,
	/AKIA[A-Z0-9]{16}/.source,
	/xox[baprs]-[a-zA-Z0-9-]{20,}/.source,
].join('|'), 'g')
const SECRET_ASSIGNMENT = new RegExp([
	/\b(?:api[_-]?key|password|secret|access[_-]?token|auth[_-]?token|client[_-]?secret)\b/.source,
	/["']?\s*(?:=>|[:=])\s*["']([^"'\s]{20,})["']/.source,
].join(''), 'gi')
const PRIVATE_ENDPOINT = new RegExp([
	/\b(?:[a-z0-9-]+\.)+/.source,
	/(?:internal|local|lan|ts\.net|trycloudflare\.com|ngrok(?:-free)?\.(?:app|io)|loca\.lt)\b/.source,
].join(''), 'i')
const IPV4 = /\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/g
const PRIVATE_SOURCE = new RegExp([
	/\bnamespace\s+TranslateWP\\Pro(?:\\|\s*;)/.source,
	/\bPro\s+(?:architecture|internals|database schema)\b/.source,
	/^\s*\*?\s*Plugin Name:\s*TranslateWP Pro\b/.source,
].join('|'), 'i')
const PRIVATE_RECORD = /\btranslatewp-(?:d[d]ef)\b|\bD[D]EF\b|docs\/spec\/(?:decisions|lanes)\//i
const PRIVATE_PATH = /(?:^|\/)(?:translatewp-(?:pro|api|d[d]ef)|\.notes|\.ssh|\.aws)(?:\/|$)/i
const SECRET_ARTIFACT = new RegExp([
	/(?:^|\/)\.env(?:\.(?!example$|sample$|template$)[^/]*)?$/.source,
	/(?:^|\/)(?:id_(?:rsa|ed25519|ecdsa)|credentials(?:\.json)?|config-local\.json)$/.source,
	/\.(?:sql|sqlite3?|db|key|p12|pfx|zip|tar|gz|7z)$/.source,
].join('|'), 'i')

function options(args) {
	const result = { version: '1.0.0' }
	for (let index = 0; index < args.length; index += 2) {
		const name = args[index]
		if (!['--root', '--mode', '--version', '--deny-file'].includes(name) || !args[index + 1]) {
			throw new Error('arguments')
		}
		result[name.slice(2)] = args[index + 1]
	}
	if (!result.root || !MODES.has(result.mode) || !/^\d+\.\d+\.\d+$/.test(result.version)) {
		throw new Error('arguments')
	}
	return result
}

function safePath(path, denied) {
	let safe = path.replace(TOKEN, '[redacted]').replace(/[\x00-\x1f\x7f]/g, '?')
	for (const value of denied) safe = safe.split(value).join('[redacted]')
	return safe
}

function git(root, args) {
	const result = spawnSync('git', ['-C', root, ...args], { encoding: 'utf8' })
	if (result.status !== 0) throw new Error('git-selection')
	return result.stdout
}

function walk(root, config = {}) {
	const { prefix = '', packageMode = false } = config
	const files = []
	for (const name of readdirSync(join(root, prefix)).sort()) {
		if (name === '.git') {
			if (packageMode) files.push(prefix ? `${prefix}/${name}` : name)
			continue
		}
		const path = prefix ? `${prefix}/${name}` : name
		if (lstatSync(join(root, path)).isDirectory()) {
			files.push(...walk(root, { prefix: path, packageMode }))
		} else files.push(path)
	}
	return files
}

function symbolic(root, path) {
	let cursor = root
	for (const segment of path.split('/')) {
		cursor = join(cursor, segment)
		if (lstatSync(cursor).isSymbolicLink()) return true
	}
	return false
}

function privateIp(value) {
	const parts = value.split('.').map(Number)
	if (parts.some(part => part > 255)) return false
	return parts[0] === 10
		|| (parts[0] === 172 && parts[1] >= 16 && parts[1] <= 31)
		|| (parts[0] === 192 && parts[1] === 168)
		|| (parts[0] === 169 && parts[1] === 254)
		|| (parts[0] === 100 && parts[1] >= 64 && parts[1] <= 127)
}

function scanLine({ line, decision, denied }) {
	const classes = []
	if (decision.test(line)) classes.push('decision-reference')
	if (PRIVATE_RECORD.test(line)) classes.push('private-record')
	if (PRIVATE_SOURCE.test(line)) classes.push('private-source')
	if (PRIVATE_ENDPOINT.test(line) || [...line.matchAll(IPV4)].some(match => privateIp(match[0]))
		|| /\b(?:f[cd][a-f0-9]{2}|fe80):[a-f0-9:]+/i.test(line)
		|| /\/(?:Users|home)\/[^/\s]+\//.test(line)) classes.push('private-endpoint')
	if (/\b[a-z][a-z0-9+.-]*:\/\/[^\s/:@]+:[^\s/@]+@/i.test(line)) {
		classes.push('credential-url')
	}
	if ([...line.matchAll(TOKEN)].length || /-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----/.test(line)
		|| [...line.matchAll(SECRET_ASSIGNMENT)].some(match => !/^\$\{[A-Z0-9_]+\}$/.test(match[1]))) {
		classes.push('secret-value')
	}
	if (denied.some(value => line.includes(value))) classes.push('private-literal')
	return classes
}

function canonicalGate({ name, root, args }) {
	const result = spawnSync('php', [join(SCRIPT_ROOT, 'tests', name), '--root', root, ...args], {
		encoding: 'utf8',
	})
	if (result.error) throw new Error('php-unavailable')
	return result.status === 0
}

function verify(config) {
	const root = realpathSync(resolve(config.root))
	const decision = new RegExp(JSON.parse(readFileSync(
		join(SCRIPT_ROOT, 'scripts/decision-reference-pattern.json'), 'utf8',
	)), 'i')
	const denied = config['deny-file']
		? readFileSync(resolve(config['deny-file']), 'utf8').split(/\r?\n/).filter(Boolean) : []
	let files
	let excludedUntracked = 0
	let excludedIgnoredEntries = 0
	if (config.mode === 'tracked') {
		if (realpathSync(git(root, ['rev-parse', '--show-toplevel']).trim()) !== root) {
			throw new Error('git-selection')
		}
		files = [...new Set(git(root, ['ls-files', '-z', '--cached']).split('\0').filter(Boolean))].sort()
		excludedUntracked = git(root, ['ls-files', '-z', '--others', '--exclude-standard'])
			.split('\0').filter(Boolean).length
		excludedIgnoredEntries = git(root, ['ls-files', '-z', '--others', '--ignored',
			'--exclude-standard', '--directory']).split('\0').filter(Boolean).length
	} else files = walk(root, { packageMode: config.mode === 'package' })
	const selected = new Set(files)
	const findings = []
	const readable = new Set()
	const digest = createHash('sha256')
	const add = ({ path, line = 1, classification }) => findings.push({
		path: safePath(path, denied), line, classification,
	})
	for (const path of files) {
		for (const classification of scanLine({ line: path, decision, denied })) {
			add({ path, classification })
		}
		if (!existsSync(join(root, path))) {
			add({ path, classification: 'missing-file' })
			continue
		}
		if (symbolic(root, path)) {
			add({ path, classification: 'symbolic-link' })
			continue
		}
		if (!lstatSync(join(root, path)).isFile()) {
			add({ path, classification: 'unsupported-file' })
			continue
		}
		readable.add(path)
		const content = readFileSync(join(root, path))
		digest.update(path).update('\0').update(String(content.length)).update('\0').update(content)
		if (PRIVATE_PATH.test(path)) add({ path, classification: 'private-source' })
		if (SECRET_ARTIFACT.test(path)) add({ path, classification: 'secret-artifact' })
		if (!path.startsWith('vendor/') && /(?:^|\/)changelog(?:\.[^/]*)?$/i.test(path)) {
			add({ path, classification: 'historical-changelog' })
		}
		content.toString('utf8').split('\n').forEach((line, index) => {
			for (const classification of scanLine({ line, decision, denied })) {
				add({ path, line: index + 1, classification })
			}
		})
	}
	const packageMode = config.mode === 'package'
	const versionPaths = ['translatewp.php', 'readme.txt', ...packageMode ? [] : ['package.json']]
	if (!versionPaths.every(path => readable.has(path))) {
		add({ path: '.', classification: 'version-contract' })
	} else if (!canonicalGate({ name: 'VersionConsistencyTest.php', root,
		args: ['--expected', config.version, ...packageMode ? ['--package'] : []] })) {
		add({ path: 'translatewp.php', classification: 'version-contract' })
	}
	if (!readable.has('readme.txt') || !canonicalGate({ name: 'ReadmeWordingTest.php', root,
		args: ['--release', config.version] })) {
		add({ path: 'readme.txt', classification: 'readme-contract' })
	}
	if (packageMode) {
		const bundle = readFileSync(join(SCRIPT_ROOT, 'scripts/bundle.mjs'), 'utf8')
		const include = bundle.match(/BUNDLE_PATHS = \[([^\]]*)\]/s)?.[1]
		if (!include) throw new Error('bundle-manifest')
		const allowed = new Set([...include.matchAll(/'([^']+)'/g)].map(match => match[1]))
		for (const path of files) {
			if (!allowed.has(path.split('/')[0])) add({ path, classification: 'package-layout' })
		}
		for (const path of ['blocks/language-switcher/block.json', 'build/translatewp.js']) {
			if (!selected.has(path)) add({ path, classification: 'package-layout' })
		}
	}
	return {
		scope: config.mode, version: config.version, files: files.length,
		excludedUntracked, excludedIgnoredEntries,
		sha256: digest.digest('hex'), status: findings.length ? 'fail' : 'pass', findings,
		limits: [
			'Pattern checks require human review of unknown hosts, addresses and architecture prose.',
			'Tracked selection excludes untracked/ignored files; inspect the selected tree and package too.',
			'History, settings, visuals, dependency audits and release acceptance need separate review.',
		],
	}
}

try {
	const report = verify(options(process.argv.slice(2)))
	console.log(JSON.stringify(report, null, 2))
	process.exitCode = report.findings.length ? 1 : 0
} catch {
	console.log(JSON.stringify({ status: 'error', classification: 'inspection-error',
		usage: 'node scripts/verify-public-release.mjs --root DIR --mode tracked|tree|package '
			+ '[--version 1.0.0] [--deny-file FILE]',
	}))
	process.exitCode = 2
}
