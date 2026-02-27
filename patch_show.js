import fs from 'fs';

const FILE_PATH = 'resources/js/Pages/Admin/Programs/Show.svelte';
let content = fs.readFileSync(FILE_PATH, 'utf8');

// 1. Staff Warning:
const oldWarning = /<div\s+class="bg-warning-50 text-warning-900 border border-warning-200 rounded-container p-4 flex items-start gap-3"\s*>\s*<AlertTriangle class="w-5 h-5 text-warning-600 shrink-0 mt-0.5" \/>\s*<div>\s*<h4 class="font-semibold text-warning-800">No supervisors assigned<\/h4>\s*<p class="text-sm mt-1">\s*This program requires a supervisor PIN to authorize overrides and force-completes, but there are no supervisors assigned.\s*<\/p>\s*<\/div>\s*<\/div>/g;

const newWarning = `<div class="bg-warning-50 text-warning-900 border border-warning-200 rounded-container p-4 flex items-start gap-3">
                        <AlertTriangle class="w-5 h-5 text-warning-600 shrink-0 mt-0.5" />
                        <div>
                            <h4 class="font-semibold text-warning-800">No supervisors assigned</h4>
                            <p class="text-sm mt-1">
                                Override requires a supervisor PIN but no supervisors are assigned. Assign supervisors below or <button type="button" class="underline hover:text-warning-950 font-medium" onclick={() => activeTab = 'settings'}>disable this in Settings</button>.
                            </p>
                        </div>
                    </div>`;

content = content.replace(oldWarning, newWarning);

if (!content.includes('Assign supervisors below or <button type="button"')) {
    // Warning block must not exist where I thought it was. Let's insert it explicitly under "Station assignments"
    const stationAssignmentHeading = /<h2 class="text-lg font-semibold text-surface-950 mb-4">\s*Station assignments\s*<\/h2>/;
    content = content.replace(stationAssignmentHeading, `<h2 class="text-lg font-semibold text-surface-950 mb-4">
                        Station assignments
                    </h2>
                    
                    {#if localSettings.require_permission_before_override && staffSupervisors.length === 0}
                        <div class="bg-warning-50 text-warning-800 border border-warning-200 rounded-container p-4 flex items-start gap-3 mb-6 shadow-sm">
                            <AlertTriangle class="w-5 h-5 text-warning-600 shrink-0 mt-0.5" />
                            <div>
                                <p class="text-sm font-medium">
                                    Override requires a supervisor PIN but no supervisors are assigned. Assign supervisors below or <button type="button" class="underline hover:text-warning-950" onclick={() => activeTab = 'settings'}>disable this in Settings</button>.
                                </p>
                            </div>
                        </div>
                    {/if}`);
}


// 2. Settings Tab: Priority First
content = content.replace(/<h3 class="font-medium text-surface-950 mb-1">\s*Strict Priority First\s*<\/h3>/g, '<h3 class="font-medium text-surface-950 mb-1">Strict priority first</h3>');
// And ensure the checkbox label says "Enable strict priority first routing"
content = content.replace(/Enable strict priority first routing/g, 'Enable strict priority first routing');


// 3. Ratio explicit labels "Priority" and "Regular" and short info sentence
const oldRatioDiv = /<div class="flex items-center gap-2">\s*<input\s*type="number"[\s\S]*?bind:value=\{localSettings.ratio_priority\}[\s\S]*?\/>\s*<span class="text-surface-500 font-medium">:<\/span>\s*<input\s*type="number"[\s\S]*?bind:value=\{localSettings.ratio_regular\}[\s\S]*?\/>\s*<\/div>/;

const newRatioDiv = `<div class="flex flex-col gap-1 mt-2">
                                <div class="flex flex-wrap items-center gap-4">
                                    <label class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-surface-700 w-16">Priority</span>
                                        <input
                                            type="number"
                                            class="input rounded-container border border-surface-200 px-3 py-1.5 w-20 text-center"
                                            min="1"
                                            max="99"
                                            bind:value={localSettings.ratio_priority}
                                        />
                                    </label>
                                    <span class="text-surface-400 font-bold">:</span>
                                    <label class="flex items-center gap-2">
                                        <input
                                            type="number"
                                            class="input rounded-container border border-surface-200 px-3 py-1.5 w-20 text-center"
                                            min="1"
                                            max="99"
                                            bind:value={localSettings.ratio_regular}
                                        />
                                        <span class="text-sm font-medium text-surface-700">Regular</span>
                                    </label>
                                </div>
                                <p class="text-xs text-surface-500 mt-1">Use strict priority when priority queue should always be served before regular.</p>
                            </div>`;

content = content.replace(oldRatioDiv, newRatioDiv);


// 4. Station selection: "More details"
const stationSelectionSelect = /<select\s*class="select rounded-container border border-surface-200 px-3 py-2 w-full max-w-sm"[\s\S]*?bind:value=\{localSettings.station_assignment_mode\}[\s\S]*?>[\s\S]*?<\/select>/;

const moreDetailsBlock = `
                            <details class="group mt-2">
                                <summary class="text-sm text-primary-600 font-medium cursor-pointer hover:underline list-none select-none flex items-center gap-1">
                                    More details
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                </summary>
                                <div class="p-3 bg-surface-50 rounded-container border border-surface-100 mt-2 text-sm text-surface-600 space-y-1.5">
                                    <p><strong>Fixed:</strong> Tokens are locked to a specific station at triage.</p>
                                    <p><strong>Shortest Queue:</strong> Tokens go to the station with the fewest waiting people.</p>
                                    <p><strong>Least Busy:</strong> Tokens go to the station that has processed the fewest people today.</p>
                                    <p><strong>Round Robin:</strong> Tokens are distributed evenly in sequence.</p>
                                    <p><strong>Least Recently Served:</strong> Tokens go to the station that has waited the longest since its last token.</p>
                                </div>
                            </details>`;

content = content.replace(stationSelectionSelect, (match) => match + moreDetailsBlock);


// 5. Stations Modal dropdown label "Strict priority first"
content = content.replace(/Yes \(priority lane first\)/g, 'Yes (strict priority first)');


// 6. mm:ss input for No-show timer
// Convert from single input to a custom dual-input component mapping back to localSettings.no_show_timer_seconds
const noShowInputRegex = /<input\s*type="number"\s*class="input rounded-container border border-surface-200 px-3 py-2 w-full max-w-xs"\s*min="5"\s*max="120"\s*placeholder="10"\s*bind:value=\{localSettings.no_show_timer_seconds\}\s*\/>/g;

content = content.replace(/let localSettings:/, `
    let noShowMinutes = $state(0);
    let noShowSeconds = $state(10);
    
    // Sync seconds to total
    $effect(() => {
        if (localSettings) {
            const raw = localSettings.no_show_timer_seconds || 10;
            noShowMinutes = Math.floor(raw / 60);
            noShowSeconds = raw % 60;
        }
    });
    
    function updateNoShowTimer() {
        let total = (noShowMinutes * 60) + (noShowSeconds || 0);
        if (total < 5) total = 5;
        if (total > 600) total = 600;
        if (localSettings) localSettings.no_show_timer_seconds = total;
    }

    let localSettings:`);

const newNoShowInput = `<div class="flex items-center gap-2 max-w-xs">
                                <div class="relative flex-1">
                                    <input 
                                        type="number" 
                                        class="input rounded-container border border-surface-200 pl-3 pr-8 py-2 w-full text-right" 
                                        min="0" max="10" 
                                        bind:value={noShowMinutes}
                                        onchange={updateNoShowTimer}
                                    />
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-surface-400 text-sm">m</span>
                                </div>
                                <span class="font-bold text-surface-400">:</span>
                                <div class="relative flex-1">
                                    <input 
                                        type="number" 
                                        class="input rounded-container border border-surface-200 pl-3 pr-8 py-2 w-full text-right" 
                                        min="0" max="59" 
                                        bind:value={noShowSeconds}
                                        onchange={updateNoShowTimer}
                                    />
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-surface-400 text-sm">s</span>
                                </div>
                            </div>`;

// If we already changed it in the previous pass:
const previousNoShowDoubleInputRegex = /<div class="flex items-center gap-2 max-w-xs">[\s\S]*?<input[\s\S]*?bind:value=\{localSettings.no_show_timer_seconds\}[\s\S]*?<\/div>/;

if (content.match(noShowInputRegex)) {
    content = content.replace(noShowInputRegex, newNoShowInput);
} else if (content.match(previousNoShowDoubleInputRegex)) {
    content = content.replace(previousNoShowDoubleInputRegex, newNoShowInput);
}

// Ensure the helper text says up to 10 minutes (600 max)
content = content.replace(/seconds \(default: 10\)/g, 'mm:ss (min 5s, max 10m)');


// 7. Tracks "Create steps" / "Manage steps"
content = content.replace(/<span class="hidden sm:inline">Steps<\/span>/g, `
                                    <span class="hidden sm:inline">
                                        {track.track_steps?.length === 0 ? 'Create steps' : 'Manage steps'}
                                    </span>
                                `);

fs.writeFileSync(FILE_PATH, content, 'utf8');
console.log('UI patch for Programs Show page completed.');
