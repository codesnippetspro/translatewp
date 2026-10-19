import * as fs from 'fs';
import * as path from 'path';

describe('persistCurrentStep guard', () =>
{
    const hookPath = path.join(__dirname, '../useSteps.ts');
    let hookSource: string;

    beforeAll(() =>
    {
        hookSource = fs.readFileSync(hookPath, 'utf-8');
    });

    it('must have persistCurrentStep function defined', () =>
    {
        expect(hookSource).toMatch(/const persistCurrentStep\s*=\s*async\s*\(\)/);
    });

    it('must wait for persistCurrentStep in nextStep', () =>
    {
        expect(hookSource).toMatch(/await persistCurrentStep\(\)/);
    });

    it('must save languages payload in persistCurrentStep', () =>
    {
        expect(hookSource).toMatch(/step:\s*'languages'/);
    });

    it('must save translation payload in persistCurrentStep', () =>
    {
        expect(hookSource).toMatch(/step:\s*'translation'/);
    });

    it('must persist the next incomplete step as resumeStep', () =>
    {
        expect(hookSource).toMatch(/resumeStep:\s*nextStepId/);
    });
});
