<script lang="ts">
  import { onMount, onDestroy } from 'svelte';
  import { getHotspotState, onHotspotChange, type HotspotInfo } from '../native/plugins/hotspotState';

  let hotspot: HotspotInfo = $state({ isHotspotOn: false, clientCount: 0, ssid: '' });
  let batteryLevel: number = $state(100);
  let isCharging: boolean = $state(false);
  let uplink: string = $state('offline');
  let unsubscribe: (() => void) | null = null;

  onMount(async () => {
    hotspot = await getHotspotState();
    unsubscribe = onHotspotChange((info) => { hotspot = info; });

    // Battery API (web platform)
    if ('getBattery' in navigator) {
      try {
        const battery = await (navigator as Navigator & { getBattery: () => Promise<{ level: number; charging: boolean }> }).getBattery();
        batteryLevel = Math.round(battery.level * 100);
        isCharging = battery.charging;
        battery.addEventListener('levelchange', () => { batteryLevel = Math.round(battery.level * 100); });
        battery.addEventListener('chargingchange', () => { isCharging = battery.charging; });
      } catch {
        // Battery API not available
      }
    }
  });

  onDestroy(() => { unsubscribe?.(); });
</script>

<div class="fixed bottom-4 right-4 z-50 rounded-xl bg-gray-900 text-white p-3 text-xs shadow-lg space-y-1">
  <div class="flex items-center gap-2">
    <span class="w-2 h-2 rounded-full {hotspot.isHotspotOn ? 'bg-green-400' : 'bg-gray-500'}"></span>
    <span>Hotspot: {hotspot.isHotspotOn ? 'ON' : 'OFF'}</span>
    {#if hotspot.isHotspotOn}
      <span class="text-gray-400">({hotspot.clientCount} clients)</span>
    {/if}
  </div>
  <div class="flex items-center gap-2">
    <span class="w-2 h-2 rounded-full {isCharging ? 'bg-yellow-400' : batteryLevel > 20 ? 'bg-green-400' : 'bg-red-400'}"></span>
    <span>Battery: {batteryLevel}%{isCharging ? ' (charging)' : ''}</span>
  </div>
  <div class="flex items-center gap-2">
    <span class="w-2 h-2 rounded-full {uplink === 'online' ? 'bg-green-400' : 'bg-orange-400'}"></span>
    <span>Uplink: {uplink}</span>
  </div>
</div>
