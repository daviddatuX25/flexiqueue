import { Capacitor } from '@capacitor/core';
import { getEdgeRuntime } from '../runtime';

export interface HotspotInfo {
  isHotspotOn: boolean;
  clientCount: number;
  ssid: string;
}

async function getHotspotState(): Promise<HotspotInfo> {
  const runtime = await getEdgeRuntime();
  if (runtime !== 'phone' || !Capacitor.isNativePlatform()) {
    return { isHotspotOn: false, clientCount: 0, ssid: '' };
  }

  try {
    const { Network } = await import('@capacitor/network');
    const status = await Network.getStatus();
    return {
      isHotspotOn: status.connectionType === 'wifi' && status.connected,
      clientCount: 0, // Requires native plugin — populated by PowerGuardPlugin later
      ssid: 'FlexiQueue-A56',
    };
  } catch {
    return { isHotspotOn: false, clientCount: 0, ssid: '' };
  }
}

function onHotspotChange(callback: (info: HotspotInfo) => void): () => void {
  if (!Capacitor.isNativePlatform()) {
    return () => {};
  }

  // Poll every 10s — lightweight alternative to native listener for demo
  const id = setInterval(async () => {
    const state = await getHotspotState();
    callback(state);
  }, 10000);

  return () => clearInterval(id);
}

export { getHotspotState, onHotspotChange };
