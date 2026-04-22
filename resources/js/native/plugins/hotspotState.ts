import { Capacitor } from '@capacitor/core';
import { getEdgeRuntime } from '../runtime';

export interface HotspotInfo {
  isHotspotOn: boolean;
  clientCount: number;
  ssid: string;
  ip: string;
  connected: boolean;
}

async function getHotspotState(): Promise<HotspotInfo> {
  const runtime = await getEdgeRuntime();
  if (runtime !== 'phone' || !Capacitor.isNativePlatform()) {
    return { isHotspotOn: false, clientCount: 0, ssid: '', ip: '', connected: false };
  }

  try {
    const { Network } = await import('@capacitor/network');
    const status = await Network.getStatus();
    // Android Network plugin exposes wifiIp as an undocumented field
    const wifiIp = (status as Record<string, unknown>).wifiIp ?? '';
    return {
      isHotspotOn: status.connectionType === 'wifi' && status.connected,
      clientCount: 0, // Requires native plugin — populated by PowerGuardPlugin later
      ssid: 'FlexiQueue-A56',
      ip: typeof wifiIp === 'string' ? wifiIp : '',
      connected: status.connected,
    };
  } catch {
    return { isHotspotOn: false, clientCount: 0, ssid: '', ip: '', connected: false };
  }
}

function onHotspotChange(callback: (info: HotspotInfo) => void): () => void {
  if (!Capacitor.isNativePlatform()) {
    return () => {};
  }

  // Poll every 30s — lightweight alternative to native listener for demo
  const id = setInterval(async () => {
    const state = await getHotspotState();
    callback(state);
  }, 30000);

  return () => clearInterval(id);
}

export { getHotspotState, onHotspotChange };
