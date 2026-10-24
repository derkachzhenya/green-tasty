import { expect, it } from 'vite-plus/test';
import { validPassword } from './authValidation';
it('counts Unicode code points and requires an actual symbol rather than whitespace', () => {
    expect(validPassword('Abcdefghijklm1🫒!')).toBe(true);
    expect(validPassword('Übcdefghijklm12!')).toBe(true);
    expect(validPassword('Letters9 ')).toBe(false);
    expect(validPassword('LongPassword12!xx')).toBe(false);
});
