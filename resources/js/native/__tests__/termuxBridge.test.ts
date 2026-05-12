import { describe, test, expect, vi, beforeEach, afterEach } from 'vitest';

// ── @capacitor/core mock ──────────────────────────────────────────────────────
// mockEdgeBundle is defined at module scope so registerPlugin() always returns
// the same object reference, regardless of how many times the module-under-test
// is dynamically imported within a test.
const mockEdgeBundle = {
    extractEdgeBundle: vi.fn(),
    startEdgeStack: vi.fn(),
    stopEdgeStack: vi.fn(),
};

vi.mock('@capacitor/core', () => ({
    Capacitor: {
        isNativePlatform: vi.fn(),
    },
    registerPlugin: vi.fn(() => mockEdgeBundle),
}));

import * as CapacitorCore from '@capacitor/core';

// ── helpers ───────────────────────────────────────────────────────────────────
function mockNative(value: boolean): void {
    vi.mocked(CapacitorCore.Capacitor.isNativePlatform).mockReturnValue(value);
}

// ── suite ─────────────────────────────────────────────────────────────────────
describe('termuxBridge', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    // ── extractEdgeBundle() ───────────────────────────────────────────────────

    describe('extractEdgeBundle()', () => {
        test('returns { alreadyExtracted: true } and skips native call on non-native', async () => {
            mockNative(false);
            const { extractEdgeBundle } = await import('../plugins/termuxBridge');

            const result = await extractEdgeBundle();

            expect(result).toEqual({ alreadyExtracted: true });
            expect(mockEdgeBundle.extractEdgeBundle).not.toHaveBeenCalled();
        });

        test('calls native plugin and returns its result on native platform', async () => {
            mockNative(true);
            mockEdgeBundle.extractEdgeBundle.mockResolvedValue({ alreadyExtracted: false, bundlePath: '/data/edge' });
            const { extractEdgeBundle } = await import('../plugins/termuxBridge');

            const result = await extractEdgeBundle();

            expect(mockEdgeBundle.extractEdgeBundle).toHaveBeenCalledWith({});
            expect(result).toEqual({ alreadyExtracted: false, bundlePath: '/data/edge' });
        });

        test('returns alreadyExtracted: true when bundle was already extracted on native', async () => {
            mockNative(true);
            mockEdgeBundle.extractEdgeBundle.mockResolvedValue({ alreadyExtracted: true });
            const { extractEdgeBundle } = await import('../plugins/termuxBridge');

            const result = await extractEdgeBundle();

            expect(result).toEqual({ alreadyExtracted: true });
        });
    });

    // ── startEdgeStack() ──────────────────────────────────────────────────────

    describe('startEdgeStack()', () => {
        test('returns true without native call on non-native', async () => {
            mockNative(false);
            const { startEdgeStack } = await import('../plugins/termuxBridge');

            const result = await startEdgeStack();

            expect(result).toBe(true);
            expect(mockEdgeBundle.startEdgeStack).not.toHaveBeenCalled();
        });

        test('returns true when native plugin starts successfully', async () => {
            mockNative(true);
            mockEdgeBundle.startEdgeStack.mockResolvedValue({ started: true });
            const { startEdgeStack } = await import('../plugins/termuxBridge');

            expect(await startEdgeStack()).toBe(true);
            expect(mockEdgeBundle.startEdgeStack).toHaveBeenCalled();
        });

        test('returns false when native plugin returns started: false', async () => {
            mockNative(true);
            mockEdgeBundle.startEdgeStack.mockResolvedValue({ started: false });
            const { startEdgeStack } = await import('../plugins/termuxBridge');

            expect(await startEdgeStack()).toBe(false);
        });

        test('returns false when native plugin throws', async () => {
            mockNative(true);
            mockEdgeBundle.startEdgeStack.mockRejectedValue(new Error('Termux not installed'));
            const { startEdgeStack } = await import('../plugins/termuxBridge');

            expect(await startEdgeStack()).toBe(false);
        });
    });

    // ── stopEdgeStack() ───────────────────────────────────────────────────────

    describe('stopEdgeStack()', () => {
        test('returns true without native call on non-native', async () => {
            mockNative(false);
            const { stopEdgeStack } = await import('../plugins/termuxBridge');

            const result = await stopEdgeStack();

            expect(result).toBe(true);
            expect(mockEdgeBundle.stopEdgeStack).not.toHaveBeenCalled();
        });

        test('returns true when native plugin stops successfully', async () => {
            mockNative(true);
            mockEdgeBundle.stopEdgeStack.mockResolvedValue({ stopped: true });
            const { stopEdgeStack } = await import('../plugins/termuxBridge');

            expect(await stopEdgeStack()).toBe(true);
            expect(mockEdgeBundle.stopEdgeStack).toHaveBeenCalled();
        });

        test('returns false when native plugin returns stopped: false', async () => {
            mockNative(true);
            mockEdgeBundle.stopEdgeStack.mockResolvedValue({ stopped: false });
            const { stopEdgeStack } = await import('../plugins/termuxBridge');

            expect(await stopEdgeStack()).toBe(false);
        });

        test('returns false when native plugin throws', async () => {
            mockNative(true);
            mockEdgeBundle.stopEdgeStack.mockRejectedValue(new Error('Termux error'));
            const { stopEdgeStack } = await import('../plugins/termuxBridge');

            expect(await stopEdgeStack()).toBe(false);
        });
    });

    // ── isStackRunning() ──────────────────────────────────────────────────────

    describe('isStackRunning()', () => {
        test('returns true when /up endpoint responds ok', async () => {
            vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true }));
            const { isStackRunning } = await import('../plugins/termuxBridge');

            expect(await isStackRunning()).toBe(true);
            expect(fetch).toHaveBeenCalledWith('http://127.0.0.1:8000/up', expect.objectContaining({ signal: expect.any(AbortSignal) }));
        });

        test('returns false when /up endpoint responds with ok: false', async () => {
            vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false }));
            const { isStackRunning } = await import('../plugins/termuxBridge');

            expect(await isStackRunning()).toBe(false);
        });

        test('returns false when fetch throws a network error', async () => {
            vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('Connection refused')));
            const { isStackRunning } = await import('../plugins/termuxBridge');

            expect(await isStackRunning()).toBe(false);
        });

        test('returns false when request times out (AbortError)', async () => {
            vi.stubGlobal('fetch', vi.fn().mockRejectedValue(
                Object.assign(new Error('Timeout'), { name: 'AbortError' })
            ));
            const { isStackRunning } = await import('../plugins/termuxBridge');

            expect(await isStackRunning()).toBe(false);
        });
    });

    // ── initializeEdge() ──────────────────────────────────────────────────────

    describe('initializeEdge()', () => {
        test('returns true immediately without any calls on non-native', async () => {
            mockNative(false);
            const { initializeEdge } = await import('../plugins/termuxBridge');

            const result = await initializeEdge();

            expect(result).toBe(true);
            expect(mockEdgeBundle.extractEdgeBundle).not.toHaveBeenCalled();
            expect(mockEdgeBundle.startEdgeStack).not.toHaveBeenCalled();
        });

        test('calls extract → start → polls health check and returns true on success', async () => {
            mockNative(true);
            mockEdgeBundle.extractEdgeBundle.mockResolvedValue({ alreadyExtracted: false });
            mockEdgeBundle.startEdgeStack.mockResolvedValue({ started: true });

            let fetchCalls = 0;
            vi.stubGlobal('fetch', vi.fn().mockImplementation(() => {
                fetchCalls++;
                if (fetchCalls < 3) return Promise.reject(new Error('not ready'));
                return Promise.resolve({ ok: true });
            }));

            const { initializeEdge } = await import('../plugins/termuxBridge');

            // Run timers so the polling loop doesn't stall on real setTimeout delays
            const promise = initializeEdge();
            // Advance clock for each failed poll + the final success
            for (let i = 0; i < 3; i++) {
                await vi.runAllTimersAsync();
            }
            const result = await promise;

            expect(mockEdgeBundle.extractEdgeBundle).toHaveBeenCalledOnce();
            expect(mockEdgeBundle.startEdgeStack).toHaveBeenCalledOnce();
            expect(result).toBe(true);
        });

        test('returns false when startEdgeStack fails on native', async () => {
            mockNative(true);
            mockEdgeBundle.extractEdgeBundle.mockResolvedValue({ alreadyExtracted: true });
            mockEdgeBundle.startEdgeStack.mockResolvedValue({ started: false });
            const { initializeEdge } = await import('../plugins/termuxBridge');

            const result = await initializeEdge();

            expect(result).toBe(false);
            // Health check should never be called if start failed
            expect(fetch).not.toHaveBeenCalled();
        });

        test('returns false when stack does not become healthy within 30 polls', async () => {
            mockNative(true);
            mockEdgeBundle.extractEdgeBundle.mockResolvedValue({ alreadyExtracted: true });
            mockEdgeBundle.startEdgeStack.mockResolvedValue({ started: true });
            vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('still not ready')));

            const { initializeEdge } = await import('../plugins/termuxBridge');

            const promise = initializeEdge();
            // Advance clock 30+ times to exhaust all polling iterations
            for (let i = 0; i < 31; i++) {
                await vi.runAllTimersAsync();
            }
            const result = await promise;

            expect(result).toBe(false);
            expect(fetch).toHaveBeenCalledTimes(30);
        });
    });
});
