import fs from 'fs';

const FILE_PATH = 'resources/js/Pages/Admin/Tokens/Index.svelte';
let content = fs.readFileSync(FILE_PATH, 'utf8');

// 1. Desktop Table View - Actions
const desktopActionsRegex = /<td class="text-right">\s*\{#if !someSelected\}[\s\S]*?\{\/if\}\s*<\/td>/g;
content = content.replace(desktopActionsRegex, `<td class="text-right">
                                    {#if !someSelected}
                                        <div class="relative inline-block text-left" class:dropdown-open={dropdownOpen === token.id}>
                                            <button
                                                type="button"
                                                class="btn btn-sm preset-tonal p-2 flex items-center justify-center hover:bg-surface-100 transition-colors"
                                                onclick={(e) => { e.stopPropagation(); dropdownOpen = dropdownOpen === token.id ? null : token.id; }}
                                                aria-label="Actions for {token.physical_id}"
                                            >
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-surface-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                                            </button>
                                            
                                            {#if dropdownOpen === token.id}
                                                <div class="absolute right-0 mt-2 w-48 bg-white rounded-container shadow-lg border border-surface-200 z-50 py-1">
                                                    <button
                                                        type="button"
                                                        class="w-full text-left px-4 py-2 text-sm text-surface-700 hover:bg-surface-50 flex items-center gap-2"
                                                        onclick={() => { dropdownOpen = null; openPrintModal([token.id]); }}
                                                    >
                                                        <Printer class="w-4 h-4" /> Print
                                                    </button>
                                                    
                                                    {#if token.status === "in_use"}
                                                        <button
                                                            type="button"
                                                            class="w-full text-left px-4 py-2 text-sm text-surface-700 hover:bg-surface-50 flex items-center gap-2"
                                                            onclick={() => { dropdownOpen = null; setTokenStatus(token, "available"); }}
                                                            disabled={submitting}
                                                        >
                                                            <CheckCircle2 class="w-4 h-4" /> Mark Available
                                                        </button>
                                                    {:else if token.status === "available"}
                                                        <button
                                                            type="button"
                                                            class="w-full text-left px-4 py-2 text-sm text-surface-700 hover:bg-surface-50 flex items-center gap-2"
                                                            onclick={() => { dropdownOpen = null; setTokenStatus(token, "deactivated"); }}
                                                            disabled={submitting}
                                                        >
                                                            <Ban class="w-4 h-4" /> Deactivate
                                                        </button>
                                                    {:else if token.status === "deactivated"}
                                                        <button
                                                            type="button"
                                                            class="w-full text-left px-4 py-2 text-sm text-surface-700 hover:bg-surface-50 flex items-center gap-2"
                                                            onclick={() => { dropdownOpen = null; setTokenStatus(token, "available"); }}
                                                            disabled={submitting}
                                                        >
                                                            <CheckCircle2 class="w-4 h-4" /> Activate
                                                        </button>
                                                    {/if}
                                                    
                                                    <hr class="border-surface-100 my-1" />
                                                    
                                                    <button
                                                        type="button"
                                                        class="w-full text-left px-4 py-2 text-sm text-error-600 hover:bg-error-50 flex items-center gap-2"
                                                        onclick={() => { dropdownOpen = null; handleDeleteToken(token); }}
                                                        disabled={submitting || token.status === "in_use"}
                                                    >
                                                        <Trash2 class="w-4 h-4" /> Delete
                                                    </button>
                                                </div>
                                            {/if}
                                        </div>
                                    {/if}
                                </td>`);

// 2. Mobile Card View - Actions
// Match from: {#if !someSelected} to {/if} under mobile view.
// It's after: class="pt-3 border-t border-surface-200 flex flex-wrap items-center justify-end gap-2"
// I will just regex the whole block.
const mobileActionsRegex = /<div\s+class="pt-3 border-t border-surface-200 flex flex-wrap items-center justify-end gap-2"\s*>\s*(?:\{#if token\.status === "in_use"\}[\s\S]*?\{\/if\}[\s\S]*?)<\/div>/g;

content = content.replace(mobileActionsRegex, `<div class="pt-3 border-t border-surface-200 flex flex-wrap items-center justify-end gap-2">
                                <button
                                    type="button"
                                    class="btn btn-sm preset-outlined bg-white text-surface-700 flex items-center justify-center gap-1.5 shadow-sm hover:bg-surface-50 px-3 py-1.5"
                                    onclick={() => openPrintModal([token.id])}
                                >
                                    <Printer class="w-3.5 h-3.5" /> Print
                                </button>
                                {#if token.status === "in_use"}
                                    <button
                                        type="button"
                                        class="btn btn-sm flex-1 preset-outlined bg-white text-surface-700 flex items-center justify-center gap-1 px-3 py-1.5 shadow-sm transition-colors hover:bg-surface-50"
                                        onclick={() => setTokenStatus(token, "available")}
                                        disabled={submitting}
                                    >
                                        <CheckCircle2 class="w-3.5 h-3.5" /> Available
                                    </button>
                                {:else if token.status === "available"}
                                    <button
                                        type="button"
                                        class="btn btn-sm flex-1 preset-outlined bg-white text-surface-700 flex items-center justify-center gap-1 px-3 py-1.5 shadow-sm transition-colors hover:bg-surface-50"
                                        onclick={() => setTokenStatus(token, "deactivated")}
                                        disabled={submitting}
                                    >
                                        <Ban class="w-3.5 h-3.5" /> Deactivate
                                    </button>
                                {:else if token.status === "deactivated"}
                                    <button
                                        type="button"
                                        class="btn btn-sm flex-1 preset-outlined bg-white text-surface-700 flex items-center justify-center gap-1 px-3 py-1.5 shadow-sm transition-colors hover:bg-surface-50"
                                        onclick={() => setTokenStatus(token, "available")}
                                        disabled={submitting}
                                    >
                                        <CheckCircle2 class="w-3.5 h-3.5" /> Activate
                                    </button>
                                {/if}
                                <button
                                    type="button"
                                    class="btn btn-sm preset-filled-error-500 hover:preset-filled-error-600 flex items-center justify-center p-2 shadow-sm shrink-0"
                                    onclick={() => handleDeleteToken(token)}
                                    disabled={submitting || token.status === "in_use"}
                                    aria-label="Delete token"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </button>
                            </div>`);

// Make sure to add the global window listener for clicks to close dropdowns, and define `dropdownOpen`
if (!content.includes('let dropdownOpen')) {
    content = content.replace(/let allFilteredSelected = \$derived[\s\S]*?;/, (match) => {
        return `${match}\n\n    let dropdownOpen = $state<number | null>(null);\n\n    function handleWindowClick() {\n        dropdownOpen = null;\n    }`;
    });

    // Add svelte:window tag
    if (!content.includes('<svelte:window')) {
        content = content.replace(/<\/svelte:head>/, `</svelte:head>\n\n<svelte:window onclick={handleWindowClick} />`);
    } else {
        content = content.replace(/<svelte:window/, `<svelte:window onclick={handleWindowClick} `);
    }
}

fs.writeFileSync(FILE_PATH, content, 'utf8');
console.log('Token actions dropdown added successfully.');
