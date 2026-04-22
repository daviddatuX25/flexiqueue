import { Capacitor } from '@capacitor/core';

export type Platform = 'web' | 'android' | 'ios';

export function getPlatform(): Platform {
  if (!Capacitor.isNativePlatform()) return 'web';
  return Capacitor.getPlatform() as Platform;
}

export function isNative(): boolean {
  return Capacitor.isNativePlatform();
}

export function isAndroid(): boolean {
  return Capacitor.getPlatform() === 'android';
}

let cachedRuntime: 'pi' | 'phone' | 'dev' | null = null;

export async function getEdgeRuntime(): Promise<'pi' | 'phone' | 'dev'> {
  if (cachedRuntime) return cachedRuntime;

  try {
    const res = await fetch('/api/edge/runtime');
    const data = await res.json();
    cachedRuntime = data.runtime;
    return cachedRuntime!;
  } catch {
    return 'dev';
  }
}