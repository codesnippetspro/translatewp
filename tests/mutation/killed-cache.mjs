import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createHash } from 'node:crypto';

const [input, output] = process.argv.slice(2);
if (!input || !output || resolve(input) === resolve(output)) {
    throw new Error('Provide distinct input report and output cache paths.');
}
const original = readFileSync(input, 'utf8');
const report = JSON.parse(original);
let retained = 0;
let removed = 0;
for (const file of Object.values(report.files)) {
    const killed = file.mutants.filter(mutant => mutant.status === 'Killed');
    retained += killed.length;
    removed += file.mutants.length - killed.length;
    file.mutants = killed;
}
writeFileSync(output, JSON.stringify(report, null, 2) + '\n');
console.log(JSON.stringify({ input, inputSha256: createHash('sha256').update(original).digest('hex'), output, retainedKills: retained, removedForReexecution: removed }));
