import { Capacitor, registerPlugin } from '@capacitor/core';

interface PowerGuardPlugin {
  startGuard(): Promise<{ guarding: boolean }>;
  stopGuard(): Promise<{ guarding: boolean }>;
}

const PowerGuard = registerPlugin<PowerGuardPlugin>('PowerGuard');

let isGuarding = false;

export async function startPowerGuard(): Promise<boolean> {
  if (!Capacitor.isNativePlatform() || Capacitor.getPlatform() !== 'android') {
    console.log('[powerGuard] Not Android native, skipping');
    return true;
  }

  try {
    const result = await PowerGuard.startGuard();
    isGuarding = result.guarding;
    return isGuarding;
  } catch (e) {
    console.error('[powerGuard] startGuard failed:', e);
    return false;
  }
}

export async function stopPowerGuard(): Promise<boolean> {
  if (!Capacitor.isNativePlatform() || Capacitor.getPlatform() !== 'android') {
    return true;
  }

  try {
    const result = await PowerGuard.stopGuard();
    isGuarding = !result.guarding;
    return true;
  } catch (e) {
    console.error('[powerGuard] stopGuard failed:', e);
    return false;
  }
}

export function isPowerGuardActive(): boolean {
  return isGuarding;
}