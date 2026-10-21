import { describe, expect, it } from 'vite-plus/test';
import { fileSize } from './excuses';

describe('fileSize', () => {
    it('reads kilobytes and megabytes', () => {
        expect(fileSize(120)).toBe('1 KB');
        expect(fileSize(340_000)).toBe('340 KB');
        expect(fileSize(1_250_000)).toBe('1.3 MB');
    });
});
