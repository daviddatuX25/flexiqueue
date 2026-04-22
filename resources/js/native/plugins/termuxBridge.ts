import { Capacitor, registerPlugin } from '@capacitor/core';

interface EdgeBundlePlugin {
  extractEdgeBundle(options: { targetPath?: string }): Promise<{
    alreadyExtracted: boolean;
    bundlePath?: string;
  }>;
  startEdgeStack(): Promise<{ started: boolean }>;
  stopEdgeStack(): Promise<{ stopped: boolean }>;
}

const EdgeBundle = registerPlugin<EdgeBundlePlugin>('EdgeBundle');

export async function extractEdgeBundle(): Promise<{
  alreadyExtracted: boolean;
  bundlePath?: string;
}> {
  if (!Capacitor.isNativePlatform()) {
    console.log('[termuxBridge] Not native platform, skipping extraction');
    return { alreadyExtracted: true };
  }
  return EdgeBundle.extractEdgeBundle({});
}

export async function startEdgeStack(): Promise<boolean> {
  if (!Capacitor.isNativePlatform()) {
    console.log('[termuxBridge] Not native platform, skipping start');
    return true;
  }

  try {
    const result = await EdgeBundle.startEdgeStack();
    return result.started;
  } catch (e) {
    console.error('[termuxBridge] startEdgeStack failed:', e);
    return false;
  }
}

export async function stopEdgeStack(): Promise<boolean> {
  if (!Capacitor.isNativePlatform()) {
    return true;
  }

  try {
    const result = await EdgeBundle.stopEdgeStack();
    return result.stopped;
  } catch (e) {
    console.error('[termuxBridge] stopEdgeStack failed:', e);
    return false;
  }
}

export async function isStackRunning(): Promise<boolean> {
  try {
    const res = await fetch('http://127.0.0.1:8000/up', {
      signal: AbortSignal.timeout(3000),
    });
    return res.ok;
  } catch {
    return false;
  }
}

export async function initializeEdge(): Promise<boolean> {
  if (!Capacitor.isNativePlatform()) return true;

  console.log('[termuxBridge] Initializing edge...');

  const { alreadyExtracted } = await extractEdgeBundle();
  console.log(`[termuxBridge] Bundle extracted: alreadyExtracted=${alreadyExtracted}`);

  const started = await startEdgeStack();
  if (!started) {
    console.error('[termuxBridge] Stack failed to start');
    return false;
  }

  // Wait up to 30s for stack to be ready
  for (let i = 0; i < 30; i++) {
    if (await isStackRunning()) {
      console.log('[termuxBridge] Stack is ready');
      return true;
    }
    await new Promise(r => setTimeout(r, 1000));
  }

  console.error('[termuxBridge] Stack did not become ready within 30s');
  return false;
}