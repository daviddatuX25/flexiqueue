import { describe, test, expect, vi, beforeEach, afterEach } from 'vitest';

// ── @capacitor/core mock ──────────────────────────────────────────────────────
vi.mock('@capacitor/core', () => ({
  Capacitor: {
    isNativePlatform: vi.fn(),
    getPlatform: vi.fn(),
  },
  registerPlugin: vi.fn(),
}));

// ── @capacitor/network mock ────────────────────────────────────────────────────
const mockNetworkGetStatus = vi.fn();
vi.mock('@capacitor/network', () => ({
  Network: {
    getStatus: () => mockNetworkGetStatus(),
  },
}));

// ── Mock getEdgeRuntime from runtime.ts ────────────────────────────────────────
vi.mock('../runtime', () => ({
  getEdgeRuntime: vi.fn(),
}));

import * as CapacitorCore from '@capacitor/core';
import { getEdgeRuntime } from '../runtime';
import { getHotspotState, onHotspotChange } from '../plugins/hotspotState';

// ── helpers ───────────────────────────────────────────────────────────────────
function mockNative(value: boolean): void {
  vi.mocked(CapacitorCore.Capacitor.isNativePlatform).mockReturnValue(value);
}

function mockRuntime(value: 'pi' | 'phone' | 'dev'): void {
  vi.mocked(getEdgeRuntime).mockResolvedValue(value);
}

const defaultHotspotInfo = {
  isHotspotOn: false,
  clientCount: 0,
  ssid: '',
  ip: '',
  connected: false,
};

// ── suite ─────────────────────────────────────────────────────────────────────
describe('hotspotState', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  // ── getHotspotState() ──────────────────────────────────────────────────────

  describe('getHotspotState()', () => {
    test('returns safe defaults when runtime is not phone', async () => {
      mockNative(true);
      mockRuntime('pi');

      const result = await getHotspotState();

      expect(result).toEqual(defaultHotspotInfo);
      expect(mockNetworkGetStatus).not.toHaveBeenCalled();
    });

    test('returns safe defaults when not on native platform', async () => {
      mockNative(false);
      mockRuntime('phone');

      const result = await getHotspotState();

      expect(result).toEqual(defaultHotspotInfo);
      expect(mockNetworkGetStatus).not.toHaveBeenCalled();
    });

    test('queries Network.getStatus on phone + native platform', async () => {
      mockNative(true);
      mockRuntime('phone');
      mockNetworkGetStatus.mockResolvedValue({
        connectionType: 'wifi',
        connected: true,
        wifiIp: '192.168.43.1',
      });

      const result = await getHotspotState();

      expect(mockNetworkGetStatus).toHaveBeenCalledOnce();
      expect(result.isHotspotOn).toBe(true);
      expect(result.connected).toBe(true);
      expect(result.ip).toBe('192.168.43.1');
    });

    test('reports hotspot off when connection is not wifi', async () => {
      mockNative(true);
      mockRuntime('phone');
      mockNetworkGetStatus.mockResolvedValue({
        connectionType: 'cellular',
        connected: true,
        wifiIp: '',
      });

      const result = await getHotspotState();

      expect(result.isHotspotOn).toBe(false);
      expect(result.connected).toBe(true);
    });

    test('falls back to defaults when Network plugin throws', async () => {
      mockNative(true);
      mockRuntime('phone');
      mockNetworkGetStatus.mockRejectedValue(new Error('Network unavailable'));

      const result = await getHotspotState();

      expect(result).toEqual(defaultHotspotInfo);
    });

    test('clientCount is always 0 (native plugin not yet implemented)', async () => {
      mockNative(true);
      mockRuntime('phone');
      mockNetworkGetStatus.mockResolvedValue({
        connectionType: 'wifi',
        connected: true,
        wifiIp: '192.168.43.1',
      });

      const result = await getHotspotState();

      expect(result.clientCount).toBe(0);
    });
  });

  // ── onHotspotChange() ───────────────────────────────────────────────────────

  describe('onHotspotChange()', () => {
    test('returns no-op unsubscribe on non-native platform', () => {
      mockNative(false);

      const unsubscribe = onHotspotChange(() => {});

      // Calling unsubscribe should not throw
      expect(() => unsubscribe()).not.toThrow();
    });

    test('returns an unsubscribe function that stops polling on native', () => {
      mockNative(true);
      mockRuntime('phone');
      mockNetworkGetStatus.mockResolvedValue({
        connectionType: 'wifi',
        connected: true,
        wifiIp: '192.168.43.1',
      });
      vi.useFakeTimers();

      const callback = vi.fn();
      const unsubscribe = onHotspotChange(callback);

      // The interval is set at 30s — advancing should trigger the callback
      expect(typeof unsubscribe).toBe('function');

      // Clean up
      unsubscribe();
    });

    test('callback is called when interval fires', async () => {
      mockNative(true);
      mockRuntime('phone');
      mockNetworkGetStatus.mockResolvedValue({
        connectionType: 'wifi',
        connected: true,
        wifiIp: '192.168.43.1',
      });
      vi.useFakeTimers();

      const callback = vi.fn();
      onHotspotChange(callback);

      // Advance 30s to trigger one poll
      await vi.advanceTimersByTimeAsync(30000);

      expect(callback).toHaveBeenCalled();
    });

    test('unsubscribe stops further callbacks', async () => {
      mockNative(true);
      mockRuntime('phone');
      mockNetworkGetStatus.mockResolvedValue({
        connectionType: 'wifi',
        connected: true,
        wifiIp: '192.168.43.1',
      });
      vi.useFakeTimers();

      const callback = vi.fn();
      const unsubscribe = onHotspotChange(callback);

      // Unsubscribe before any interval fires
      unsubscribe();

      await vi.advanceTimersByTimeAsync(60000);

      expect(callback).not.toHaveBeenCalled();
    });
  });
});