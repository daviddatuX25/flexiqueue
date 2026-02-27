import fs from 'fs';

const FILE_PATH = 'resources/js/Pages/Admin/Programs/Show.svelte';
let content = fs.readFileSync(FILE_PATH, 'utf8');

// 1. Open Triage button -> View Program
content = content.replace(
    /href="\/triage"\s*class="btn preset-filled-primary-500[^>]+>\s*<Activity class="w-4 h-4" \/>\s*Open Triage/m,
    `href={\`/admin/programs/\${program.id}?tab=stations\`}
                        class="btn preset-filled-primary-500 flex items-center gap-2 shadow-sm hover:shadow-md transition-shadow"
                        title="Open stations to manage the queue"
                    >
                        <Activity class="w-4 h-4" />
                        View Program`
);

// 2. Reorder Tab Buttons
// Need to find the exact tablist block and replace it.
const tabsRegex = /<div role="tablist" class="tabs">[\s\S]*?<\/div>/;
const newTabsList = `<div role="tablist" class="tabs gap-2 overflow-x-auto pb-1">
            <button type="button" role="tab" class="tab w-fit whitespace-nowrap px-4" class:tab-active={activeTab === "overview"} onclick={() => (activeTab = "overview")}>Overview</button>
            <button type="button" role="tab" class="tab w-fit whitespace-nowrap px-4" class:tab-active={activeTab === "processes"} onclick={() => (activeTab = "processes")}>Processes</button>
            <button type="button" role="tab" class="tab w-fit whitespace-nowrap px-4" class:tab-active={activeTab === "stations"} onclick={() => (activeTab = "stations")}>Stations</button>
            <button type="button" role="tab" class="tab w-fit whitespace-nowrap px-4" class:tab-active={activeTab === "staff"} onclick={() => (activeTab = "staff")}>Staff</button>
            <button type="button" role="tab" class="tab w-fit whitespace-nowrap px-4" class:tab-active={activeTab === "tracks"} onclick={() => (activeTab = "tracks")}>Tracks</button>
            <button type="button" role="tab" class="tab w-fit whitespace-nowrap px-4" class:tab-active={activeTab === "settings"} onclick={() => (activeTab = "settings")}>Settings</button>
        </div>`;
content = content.replace(tabsRegex, newTabsList);

// 3. Reorder the tab blocks
// Use split on the {else if activeTab === ...} statements
const delimRegex = /({#if activeTab === "overview"}|{:else if activeTab === "tracks"}|{:else if activeTab === "settings"}|{:else if activeTab === "processes"}|{:else if activeTab === "staff"}|{:else if activeTab === "stations"}|{:else}\s*<!-- Stations tab \(BD-010\) -->)/;
let parts = content.split(delimRegex);

function extractBlock(name) {
    if (name === "stations") {
        const idx = parts.findIndex(p => p && p.includes("Stations tab (BD-010)"));
        if (idx !== -1) {
            let blockContent = parts[idx + 1];
            if (blockContent.endsWith('        {/if}\n')) {
                blockContent = blockContent.replace(/\s*{\/if}\n$/, '');
            }
            return `        {:else if activeTab === "stations"}` + blockContent;
        } else {
            const idx2 = parts.findIndex(p => p === '{:else if activeTab === "stations"}');
            if (idx2 !== -1) {
                return parts[idx2] + parts[idx2 + 1];
            }
        }
    }
    const idx = parts.findIndex(p => p === `{:else if activeTab === "${name}"}`);
    if (idx !== -1) return parts[idx] + parts[idx + 1];
    if (name === "overview") {
        const idxOv = parts.findIndex(p => p === `{#if activeTab === "overview"}`);
        return parts[idxOv] + parts[idxOv + 1];
    }
    return '';
}

const overviewBlock = extractBlock("overview");
const tracksBlock = extractBlock("tracks");
const settingsBlock = extractBlock("settings");
const processesBlock = extractBlock("processes");
const staffBlock = extractBlock("staff");
const stationsBlock = extractBlock("stations");

if (overviewBlock && tracksBlock && settingsBlock && processesBlock && staffBlock && stationsBlock) {
    const newBlocks =
        overviewBlock +
        processesBlock +
        stationsBlock +
        staffBlock +
        tracksBlock +
        settingsBlock +
        '\n        {/if}\n';

    const startIdx = content.indexOf('{#if activeTab === "overview"}');
    const endIdx = content.lastIndexOf('{/if}\n</AdminLayout>');
    if (startIdx !== -1 && endIdx !== -1) {
        const beforeBlocks = content.substring(0, startIdx);
        // Find the last {/if} of the tab structure which usually sits right before the <!-- Create Program Modal -->
        const possibleEndMarker = '        {/if}\n\n    <!-- Create Program Modal -->';
        const replaceEndIdx = content.indexOf(possibleEndMarker);
        let afterBlocks = '';
        if (replaceEndIdx !== -1) {
            afterBlocks = content.substring(replaceEndIdx + 14); // Skip the {/if}
        } else {
            afterBlocks = content.substring(content.lastIndexOf('        {/if}\n') + 14);
        }

        content = beforeBlocks + newBlocks + afterBlocks;
    }
}

// 4. Track "Steps" button
content = content.replace(
    /<button[^>]+>\s*<GitMerge class="w-3\.5 h-3\.5" \/> Steps\s*<\/button>/g,
    `<button
                                    type="button"
                                    class="btn preset-tonal btn-sm flex items-center gap-1.5 bg-white border border-surface-200 shadow-sm hover:bg-surface-50"
                                    onclick={() => openStepModal(track)}
                                    disabled={submitting}
                                >
                                    <GitMerge class="w-3.5 h-3.5" /> {(track.steps ?? []).length === 0 ? "Create steps" : "Manage steps"}
                                </button>`
);

// 5. Settings tweaks
const noShowTimerRegex = /<input[^>]+id="no-show-timer"[\s\S]*?<span class="text-sm text-surface-600">seconds \(default: 10\)<\/span>/;
content = content.replace(noShowTimerRegex,
    `<input type="number" class="input rounded-container border border-surface-200 px-3 py-2 w-20 text-center text-surface-950 bg-white shadow-sm" min="0" max="10" placeholder="Min" value={Math.floor(settingsNoShowTimer / 60)} onchange={(e) => { const m = parseInt(e.currentTarget.value) || 0; const s = settingsNoShowTimer % 60; settingsNoShowTimer = Math.max(5, Math.min(600, m * 60 + s)); }} />
                                    <span class="font-bold text-surface-400">:</span>
                                    <input type="number" class="input rounded-container border border-surface-200 px-3 py-2 w-20 text-center text-surface-950 bg-white shadow-sm" min="0" max="59" placeholder="Sec" value={settingsNoShowTimer % 60} onchange={(e) => { const s = parseInt(e.currentTarget.value) || 0; const m = Math.floor(settingsNoShowTimer / 60); settingsNoShowTimer = Math.max(5, Math.min(600, m * 60 + s)); }} />
                                    <span class="text-sm text-surface-600 ml-2">(mm:ss, default: 0:10)</span>`
);

content = content.replace(/Priority First\s*<\/h3>/g, 'Strict priority first</h3>');
content = content.replace(/Enable priority first routing/g, 'Enable strict priority first routing');

const ratioBlockRegex = /<span class="font-medium text-surface-700">Ratio Priority:Regular<\/span>[\s\S]*?<\/div>\s*<\/div>/;
content = content.replace(ratioBlockRegex, `<div class="flex flex-col">
                                            <span class="font-medium text-surface-700">Alternate Ratio</span>
                                            <span class="text-xs text-surface-500 mt-1">Call Priority then Regular.</span>
                                        </div>
                                        <div class="flex flex-row items-center gap-2 ml-auto">
                                            <label class="flex flex-col items-center gap-1">
                                                <span class="text-[10px] uppercase font-bold text-surface-500 tracking-wider">Priority</span>
                                                <input type="number" class="input rounded border border-surface-300 px-2 py-1 flex-1 min-w-[3rem] w-14 text-center text-surface-950 bg-white" min="1" max="10" bind:value={settingsAlternateRatioP} />
                                            </label>
                                            <span class="font-bold text-surface-400 mt-5">:</span>
                                            <label class="flex flex-col items-center gap-1">
                                                <span class="text-[10px] uppercase font-bold text-surface-500 tracking-wider">Regular</span>
                                                <input type="number" class="input rounded border border-surface-300 px-2 py-1 flex-1 min-w-[3rem] w-14 text-center text-surface-950 bg-white" min="1" max="10" bind:value={settingsAlternateRatioR} />
                                            </label>
                                        </div>
                                    </div>`);

const detailsBlockRegex = /<p class="text-xs text-surface-500 mt-1">\s*When multiple stations serve the same process, how to pick the station.\s*<\/p>/;
content = content.replace(detailsBlockRegex, `<p class="text-xs text-surface-500 mt-1">
                                    When multiple stations serve the same process, how to pick the station.
                                </p>
                                <details class="mt-2 text-xs text-surface-500">
                                    <summary class="cursor-pointer text-primary-600 hover:text-primary-700 font-medium">More details</summary>
                                    <ul class="list-disc ml-4 mt-1 space-y-1">
                                        <li><b>Fixed:</b> First configured station.</li>
                                        <li><b>Shortest Queue:</b> Fewest waiting clients.</li>
                                        <li><b>Least Busy:</b> Lowest active load.</li>
                                        <li><b>Round Robin:</b> Rotate fairly among stations.</li>
                                        <li><b>Least Recently Served:</b> Station idle the longest.</li>
                                    </ul>
                                </details>`);

const staffWarningRegex = /{:else if activeTab === "staff"}\s*<div class="space-y-8">/;
content = content.replace(staffWarningRegex, `{:else if activeTab === "staff"}
            <div class="space-y-8">
                {#if program.settings?.require_permission_before_override && staffSupervisors.length === 0}
                    <div class="rounded-container border-l-4 border-l-warning-500 border border-warning-200 bg-warning-50 p-4 flex gap-3 shadow-sm mb-6">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-warning-600 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <div>
                            <p class="text-sm text-warning-900 font-medium">Override requires a supervisor PIN, but no supervisors are assigned.</p>
                            <p class="text-xs text-warning-800/80 mt-1">Assign supervisors below or disable this in Settings to prevent blocking.</p>
                        </div>
                    </div>
                {/if}`);

fs.writeFileSync(FILE_PATH, content, 'utf8');
console.log('UI Patches applied.');
