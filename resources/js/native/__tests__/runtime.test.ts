import { describe, test, expect, vi, beforeEach, afterEach } from 'vitest';

// ── @capacitor/core mock ──────────────────────────────────────────────────────
vi.mock('@capacitor/core', () => ({
    Capacitor: {
        isNativePlatform: vi.fn(),
        getPlatform: vi.fn(),
    },
}));

// Import after mock so the module-under-test receives the mocked Capacitor.
// We import once and re-use: vi.resetModules() is NOT used here because it would
// break the static vi.mock() hoisting. Instead, each test sets its mock return
// value before calling the function.
import * as CapacitorCore from '@capacitor/core';
import { getPlatform, isNative, isAndroid, getEdgeRuntime } from '../runtime';

// ── helpers ───────────────────────────────────────────────────────────────────
function mockNative(value: boolean): void {
    vi.mocked(CapacitorCore.Capacitor.isNativePlatform).mockReturnValue(value);
}

function mockPlatform(platform: string): void {
    vi.mocked(CapacitorCore.Capacitor.getPlatform).mockReturnValue(platform);
}

// ── suite ─────────────────────────────────────────────────────────────────────
describe('runtime', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    // ── getPlatform() ─────────────────────────────────────────────────────────

    describe('getPlatform()', () => {
        test('returns "web" when not on a native platform', () => {
            mockNative(false);
            expect(getPlatform()).toBe('web');
        });

        test('returns "android" on native Android', () => {
            mockNative(true);
            mockPlatform('android');
            expect(getPlatform()).toBe('android');
        });

        test('returns "ios" on native iOS', () => {
            mockNative(true);
            mockPlatform('ios');
            expect(getPlatform()).toBe('ios');
        });

        test('ignores Capacitor.getPlatform() value when not native (always "web")', () => {
            // Even if Capacitor internally says "android", if isNativePlatform is false
            // the function must still return "web".
            mockNative(false);
            mockPlatform('android');
            expect(getPlatform()).toBe('web');
        });
    });

    // ── isNative() ────────────────────────────────────────────────────────────

    describe('isNative()', () => {
        test('returns true on native platform', () => {
            mockNative(true);
            expect(isNative()).toBe(true);
        });

        test('returns false on non-native platform', () => {
            mockNative(false);
            expect(isNative()).toBe(false);
        });
    });

    // ── isAndroid() ───────────────────────────────────────────────────────────

    describe('isAndroid()', () => {
        test('returns true for android platform', () => {
            mockPlatform('android');
            expect(isAndroid()).toBe(true);
        });

        test('returns false for ios platform', () => {
            mockPlatform('ios');
            expect(isAndroid()).toBe(false);
        });

        test('returns false for web platform', () => {
            mockPlatform('web');
            expect(isAndroid()).toBe(false);
        });
    });

    // ── getEdgeRuntime() ──────────────────────────────────────────────────────

    describe('getEdgeRuntime()', () => {
        // NOTE: getEdgeRuntime() caches the first successful result in a
        // module-level variable.  Because we import the module once (at the top
        // of this file), the cache persists across tests in this describe block.
        // To work around this we order tests so the successful-fetch tests come
        // first, and we verify the cached value rather than fighting the cache.

        test('returns "pi" runtime value from the API', async () => {
            vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
                json: vi.fn().mockResolvedValue({ runtime: 'pi' }),
            }));
            const result = await getEdgeRuntime();
            expect(result).toBe('pi');
            expect(fetch).toHaveBeenCalledWith('/api/edge/runtime');
        });

        test('caches the result — subsequent calls skip fetch', async () => {
            // The cache was populated by the previous test ('pi').
            const mockFetch = vi.fn();
            vi.stubGlobal('fetch', mockFetch);

            const result = await getEdgeRuntime();
            expect(result).toBe('pi');           // same cached value
            expect(mockFetch).not.toHaveBeenCalled(); // no network call
        });

        test('returns "dev" when fetch throws a network error', async () => {
            // Directly test the fallback by importing a fresh module instance
            // that has an empty cache.  We do this via resetModules + re-import.
            vi.resetModules();
            vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('Network error')));

            const { getEdgeRuntime: fresh } = await import('../runtime');
            const result = await fresh();
            expect(result).toBe('dev');
        });

        test('returns "dev" when the server responds with a non-JSON body', async () => {
            vi.resetModules();
            vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
                json: vi.fn().mockRejectedValue(new SyntaxError('Unexpected token')),
            }));

            const { getEdgeRuntime: fresh } = await import('../runtime');
            const result = await fresh();
            expect(result).toBe('dev');
        });

        test('returns "phone" runtime value from the API', async () => {
            vi.resetModules();
            vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
                json: vi.fn().mockResolvedValue({ runtime: 'phone' }),
            }));

            const { getEdgeRuntime: fresh } = await import('../runtime');
            expect(await fresh()).toBe('phone');
        });
    });
});
