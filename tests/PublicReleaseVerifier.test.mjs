import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterEach, test } from 'node:test'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const SCRIPT = join(ROOT, 'scripts/verify-public-release.mjs')
const temporary = []
const fairUse = 'Every plan includes AI translation with a fair-use allowance per 30 days. '
	+ 'When your account reaches its allowance, translated pages keep working for visitors and new '
	+ 'translation pauses until the window moves on. On Orbital, add your own AI key to keep going.'

function write(root, path, contents) {
	mkdirSync(dirname(join(root, path)), { recursive: true })
	writeFileSync(join(root, path), contents)
}

function candidate() {
	const root = mkdtempSync(join(tmpdir(), 'public-release-fixture-'))
	temporary.push(root)
	write(root, 'translatewp.php', "<?php\n/**\n * Version: 1.0.0\n */\ndefine( 'TRANSLATEWP_VERSION', '1.0.0' );\n")
	write(root, 'package.json', '{"version":"1.0.0","wpSlug":"translate"}\n')
	write(root, 'readme.txt', '=== TranslateWP ===\nStable tag: 1.0.0\n' + fairUse + '\n'
		+ 'Service terms: https://translatewp.ai/terms. Privacy policy: https://translatewp.ai/privacy\n'
		+ 'The service endpoint is api.translatewp.ai. Pro plans include more tools.\n'
		+ '== Changelog ==\n= 1.0.0 =\nInitial release.\n')
	return root
}

function verify(root, mode = 'tree', extra = []) {
	const result = spawnSync(process.execPath, [SCRIPT, '--root', root, '--mode', mode, ...extra], {
		encoding: 'utf8', timeout: 30000,
	})
	return { ...result, report: result.stdout ? JSON.parse(result.stdout) : null }
}

function git(root, args) {
	const result = spawnSync('git', ['-C', root, ...args], { encoding: 'utf8' })
	assert.equal(result.status, 0, result.stderr)
}

function rejected(root, classification, mode = 'tree', extra = []) {
	const result = verify(root, mode, extra)
	assert.equal(result.status, 1, result.stderr || result.stdout)
	assert.ok(result.report?.findings.some(finding => finding.classification === classification),
		JSON.stringify(result.report))
	return result
}

afterEach(() => {
	for (const path of temporary.splice(0)) rmSync(path, { recursive: true, force: true })
})

test('a clean selected tree with public product and regex language passes', () => {
	const root = candidate()
	write(root, 'tests/example.php', "<?php\n$pattern = '/TWP[- ][0-9]+/';\n$url = 'https://example.test';\n")
	const result = verify(root)
	assert.equal(result.status, 0, result.stderr || result.stdout)
	assert.equal(result.report.scope, 'tree')
	assert.equal(result.report.files, 4)
	assert.match(result.report.sha256, /^[a-f0-9]{64}$/)
})

test('selected documentation and fixture files cannot carry internal references', () => {
	for (const path of ['docs/notes.md', 'tests/innocent.test.js']) {
		const root = candidate()
		write(root, path, 'First line\n' + ['TWP', '209'].join('-') + '\n')
		const result = rejected(root, 'decision-reference')
		assert.deepEqual(result.report.findings[0], { path, line: 2, classification: 'decision-reference' })
	}
})

test('numbered lane notes and private register paths fail without banning product plans', () => {
	const root = candidate()
	write(root, 'docs/notes.md', ['lane', '019'].join('-') + '\n')
	rejected(root, 'decision-reference')
	write(root, 'docs/notes.md', ['translatewp', ['dd', 'ef'].join('')].join('-') + '/' + ['docs', 'spec', 'decisions', '\n'].join('/'))
	rejected(root, 'private-record')
})

test('private endpoints and tunnel hosts fail even in tests', () => {
	for (const endpoint of [
		'https://' + ['staff', 'internal'].join('.'),
		'https://preview.' + ['try', 'cloudflare'].join('') + '.com',
		'https://' + [10, 23, 45, 67].join('.'),
		'postgresql:' + '//user:password@db.example.test/db',
	]) {
		const root = candidate()
		write(root, 'tests/request.txt', endpoint)
		rejected(root, endpoint.startsWith('postgresql:') ? 'credential-url' : 'private-endpoint')
	}
})

test('optional private literal policy reports locations without disclosing addresses', () => {
	const root = candidate()
	const policy = join(mkdtempSync(join(tmpdir(), 'public-release-policy-')), 'deny.txt')
	temporary.push(dirname(policy))
	const address = 'Corporate Mailbox 8472'
	writeFileSync(policy, address + '\n')
	write(root, 'docs/contact.md', address)
	write(root, 'docs/' + address + '.md', 'contact')
	const result = rejected(root, 'private-literal', 'tree', ['--deny-file', policy])
	assert.equal(result.stdout.includes(address), false)
})

test('literal credentials fail and neither output stream reveals the value', () => {
	const root = candidate()
	const secret = ['ghp', 'Q7wR8vT9yU0pA1sD2fG3hJ4kL5zX6cV7bN8m'].join('_')
	write(root, 'src/settings.js', "const token = '" + secret + "';\n")
	const result = rejected(root, 'secret-value')
	assert.equal((result.stdout + result.stderr).includes(secret), false)
})

test('private source and secret artifacts fail without reading key contents into output', () => {
	for (const path of ['.env', 'backup.sql', 'id_rsa', 'translatewp-pro/Bootstrap.php']) {
		const root = candidate()
		write(root, path, 'fixture')
		rejected(root, path.startsWith('translatewp-pro/') ? 'private-source' : 'secret-artifact')
	}
})

test('premium interoperability strings pass while private implementation namespaces fail', () => {
	const root = candidate()
	write(root, 'tests/interoperability.php', "<?php\n$plugin = 'translatewp-pro/translatewp-pro.php';\n")
	assert.equal(verify(root).status, 0)
	write(root, 'Components/Hidden.php', '<?php\nnamespace TranslateWP\\Pro\\Internal;\n')
	rejected(root, 'private-source')
})

test('tracked mode uses working files and excludes untracked secrets and git history explicitly', () => {
	const root = candidate()
	git(root, ['init', '-q'])
	git(root, ['add', '.'])
	write(root, '.env', 'do-not-read-this-value')
	mkdirSync(join(root, '.git', 'objects', 'fixture'), { recursive: true })
	write(root, '.git/objects/fixture/private', ['TWP', '209'].join('-'))
	const result = verify(root, 'tracked')
	assert.equal(result.status, 0, result.stderr || result.stdout)
	assert.equal(result.report.excludedUntracked, 1)
	rejected(root, 'secret-artifact')
	write(root, 'translatewp.php', readFileSync(join(root, 'translatewp.php'), 'utf8')
		+ '\n' + ['TWP', '209'].join('-'))
	rejected(root, 'decision-reference', 'tracked')
})

test('staged deletions are absent from tracked selection', () => {
	const root = candidate()
	write(root, 'changelog.txt', 'obsolete')
	git(root, ['init', '-q'])
	git(root, ['add', '.'])
	git(root, ['rm', '-q', '--cached', 'changelog.txt'])
	assert.equal(verify(root, 'tracked').status, 0)
	rejected(root, 'historical-changelog')
})

test('version, current readme wording and release-only changelog reuse canonical gates', () => {
	const root = candidate()
	write(root, 'package.json', '{"version":"0.10.30"}')
	rejected(root, 'version-contract')
	write(root, 'package.json', '{"version":"1.0.0"}')
	const readme = readFileSync(join(root, 'readme.txt'), 'utf8')
	write(root, 'readme.txt', readme + '= 0.10.30 =\nOld version.\n')
	rejected(root, 'readme-contract')
	write(root, 'readme.txt', readme.replace(fairUse, 'Translation without artificial limits.'))
	rejected(root, 'readme-contract')
})

test('candidate symlinks fail without following external files', () => {
	const root = candidate()
	const external = mkdtempSync(join(tmpdir(), 'public-release-external-'))
	temporary.push(external)
	writeFileSync(join(external, 'secret'), 'fixture')
	const linked = spawnSync('ln', ['-s', join(external, 'secret'), join(root, 'innocent.txt')])
	assert.equal(linked.status, 0)
	rejected(root, 'symbolic-link')
})

test('an actual generated zip is inspected after extraction including untracked build output', () => {
	const source = candidate()
	const staging = mkdtempSync(join(tmpdir(), 'public-release-package-'))
	temporary.push(staging)
	const root = join(staging, 'translate')
	for (const path of ['translatewp.php', 'readme.txt']) write(root, path, readFileSync(join(source, path)))
	write(root, 'build/translatewp.js', 'console.log("public");\n')
	write(root, 'blocks/language-switcher/block.json', '{"name":"translatewp/language-switcher"}')
	const zip = join(staging, 'candidate.zip')
	assert.equal(spawnSync('zip', ['-qr', zip, 'translate'], { cwd: staging }).status, 0)
	const unpacked = join(staging, 'unpacked')
	mkdirSync(unpacked)
	assert.equal(spawnSync('unzip', ['-q', zip, '-d', unpacked]).status, 0)
	assert.equal(verify(join(unpacked, 'translate'), 'package').status, 0)
	write(root, 'build/private.js', ['TWP', '209'].join('-'))
	assert.equal(spawnSync('zip', ['-qr', zip, 'translate'], { cwd: staging }).status, 0)
	rmSync(unpacked, { recursive: true })
	assert.equal(spawnSync('unzip', ['-q', zip, '-d', unpacked]).status, 0)
	rejected(join(unpacked, 'translate'), 'decision-reference', 'package')
	write(root, 'build/private.js', 'clean')
	write(root, 'build/.env', 'fixture')
	rejected(root, 'secret-artifact', 'package')
})

test('package mode rejects missing generated assets and unexpected package paths', () => {
	const root = candidate()
	rejected(root, 'package-layout', 'package')
	write(root, 'build/translatewp.js', 'public')
	write(root, 'blocks/language-switcher/block.json', '{}')
	rmSync(join(root, 'package.json'))
	write(root, 'tests/debug.txt', 'fixture')
	rejected(root, 'package-layout', 'package')
})

test('content changes alter the inspected tree digest without changing candidate files', () => {
	const root = candidate()
	const before = createHash('sha256').update(readFileSync(join(root, 'readme.txt'))).digest('hex')
	const first = verify(root)
	assert.equal(first.status, 0)
	assert.equal(createHash('sha256').update(readFileSync(join(root, 'readme.txt'))).digest('hex'), before)
	write(root, 'docs/public.md', 'Public contributor guide.\n')
	assert.notEqual(verify(root).report.sha256, first.report.sha256)
})

test('identifiers hidden in filenames are rejected', () => {
	const root = candidate()
	write(root, 'docs/' + ['TWP', '209'].join('-') + '.md', 'notes')
	rejected(root, 'decision-reference')
})

test('ignored artifacts are explicitly counted outside the tracked selection', () => {
	const root = candidate()
	write(root, '.gitignore', '.env\nbuild/\n')
	git(root, ['init', '-q'])
	git(root, ['add', '.'])
	write(root, '.env', 'fixture')
	const result = verify(root, 'tracked')
	assert.equal(result.status, 0)
	assert.equal(result.report.excludedIgnoredEntries, 1)
})

test('missing tracked files fail with a safe location rather than an incomplete digest', () => {
	const root = candidate()
	write(root, 'docs/public.txt', 'public')
	git(root, ['init', '-q'])
	git(root, ['add', '.'])
	rmSync(join(root, 'docs/public.txt'))
	rejected(root, 'missing-file', 'tracked')
})

test('canonical readme validation never follows candidate symlinks', () => {
	const root = candidate()
	rmSync(join(root, 'readme.txt'))
	const external = mkdtempSync(join(tmpdir(), 'public-release-pipe-'))
	temporary.push(external)
	assert.equal(spawnSync('mkfifo', [join(external, 'unreadable')]).status, 0)
	assert.equal(spawnSync('ln', ['-s', join(external, 'unreadable'), join(root, 'readme.txt')]).status, 0)
	rejected(root, 'symbolic-link')
})

test('packages cannot conceal repository metadata behind the history exclusion', () => {
	const root = candidate()
	rmSync(join(root, 'package.json'))
	write(root, 'build/translatewp.js', 'public')
	write(root, 'blocks/language-switcher/block.json', '{}')
	write(root, '.git/config', 'fixture')
	rejected(root, 'package-layout', 'package')
})

test('public verification tooling and its synthetic fixtures can remain in a clean public tree', () => {
	const root = candidate()
	for (const path of ['scripts/verify-public-release.mjs', 'scripts/decision-reference-pattern.json',
		'tests/PublicReleaseVerifier.test.mjs']) write(root, path, readFileSync(join(ROOT, path)))
	const result = verify(root)
	assert.equal(result.status, 0, result.stdout)
})

test('a test prefix does not exempt a literal credential assignment from inspection', () => {
	const root = candidate()
	const secret = ['test', 'R8vT9yU0pA1sD2fG3hJ4kL5zX6cV7bN8m'].join('')
	write(root, 'src/settings.js', "const password = '" + secret + "';\n")
	const result = rejected(root, 'secret-value')
	assert.equal((result.stdout + result.stderr).includes(secret), false)
})

test('a renamed premium bootstrap still fails on its actual plugin header', () => {
	const root = candidate()
	write(root, 'Includes/Extra.php', '<?php\n/**\n * Plugin Name: TranslateWP Pro\n */\n')
	rejected(root, 'private-source')
})

test('numeric version prefixes cannot conceal a prerelease header or stable tag', () => {
	for (const path of ['translatewp.php', 'readme.txt']) {
		const root = candidate()
		const contents = readFileSync(join(root, path), 'utf8')
		write(root, path, contents.replace('1.0.0', '1.0.0-beta'))
		rejected(root, 'version-contract')
	}
})

test('PHP array and JavaScript credentials fail through the CLI without revealing literal values', () => {
	const secret = ['fixture_only', 'abcdefghijklmnopqrstuvwxyz'].join('_')
	for (const assignment of [
		`$config = ['api_key' => '${secret}'];`,
		`$config = ["password" => "${secret}"];`,
		`$config = array('access_token' => '${secret}');`,
		`const config = { apiKey: '${secret}' };`,
		`const apiKey = '${secret}';`,
	]) {
		const root = candidate()
		const path = assignment.startsWith('$') ? 'runtime.php' : 'src/settings.js'
		write(root, path, (assignment.startsWith('$') ? '<?php\n' : '\n') + assignment + '\n')
		const result = rejected(root, 'secret-value')
		assert.ok(result.report.findings.some(finding => finding.path === path && finding.line === 2))
		assert.equal((result.stdout + result.stderr).includes(secret), false)
	}
})

test('credential variables and configuration references remain allowed', () => {
	const root = candidate()
	write(root, 'runtime.php', "<?php\n$config = ['api_key' => $api_key, 'password' => $settings['password']];\n")
	write(root, 'src/settings.js', 'const config = { apiKey: settings.apiKey, password: getPassword() };\n')
	const result = verify(root)
	assert.equal(result.status, 0, result.stdout)
})


test('the domain policy citation is allowed only in agent instruction files', () => {
	const root = candidate()
	const citation = ['TWP', '243'].join('-')
	for (const path of ['AGENTS.md', 'CLAUDE.md', '.github/copilot-instructions.md']) {
		write(root, path, 'Domain policy (' + citation + ')\n')
	}
	assert.equal(verify(root).status, 0)
	write(root, 'Components/Example.php', '<?php // ' + citation)
	rejected(root, 'decision-reference')
})

test('other internal citations remain forbidden in agent instruction files', () => {
	const root = candidate()
	write(root, 'AGENTS.md', ['TWP', '209'].join('-'))
	rejected(root, 'decision-reference')
})


test('patch releases retain released changelog entries and exclude pre-release history', () => {
	const root = candidate()
	for (const file of ['translatewp.php', 'package.json', 'readme.txt']) {
		write(root, file, readFileSync(join(root, file), 'utf8').replaceAll('1.0.0', '1.0.1'))
	}
	const readme = readFileSync(join(root, 'readme.txt'), 'utf8')
	write(root, 'readme.txt', readme + '= 1.0.0 =\nInitial release.\n')
	assert.equal(verify(root, 'tree', ['--version', '1.0.1']).status, 0)
	write(root, 'readme.txt', readme + '= 0.10.30 =\nOld version.\n')
	rejected(root, 'readme-contract', 'tree', ['--version', '1.0.1'])
})


test('PHP source sweep allows the domain citation only in agent instructions', () => {
	const root = candidate()
	const citation = ['TWP', '243'].join('-')
	write(root, 'tests/DecisionReferenceSweepTest.php', readFileSync(join(ROOT, 'tests/DecisionReferenceSweepTest.php')))
	write(root, 'scripts/decision-reference-pattern.json', readFileSync(join(ROOT, 'scripts/decision-reference-pattern.json')))
	mkdirSync(join(root, 'build'))
	write(root, '.github/copilot-instructions.md', citation)
	const run = () => spawnSync('php', [join(root, 'tests/DecisionReferenceSweepTest.php')], { encoding: 'utf8' })
	assert.equal(run().status, 0)
	write(root, 'Components/Example.php', '<?php // ' + citation)
	assert.equal(run().status, 1)
})
