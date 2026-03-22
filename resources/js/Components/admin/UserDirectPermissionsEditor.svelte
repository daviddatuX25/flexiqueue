<script lang="ts">
    /**
     * Direct Spatie permissions multi-select for admin user edit (RBAC Phase 4).
     * Long list: search filters; grouped by first segment (platform, admin, …).
     */
    let {
        assignablePermissions = [],
        selected = $bindable([] as string[]),
        effectivePermissions = [],
        supervisorProgramCount = 0,
        canAssignPlatformManage = false,
        disabled = false,
        errors = {} as Record<string, string[] | string | undefined>,
        /** When set, replaces the default “Direct permissions” intro (e.g. site/program Spatie team scope). */
        description = null as string | null,
        showEffectiveReadout = true,
    }: {
        assignablePermissions: string[];
        selected: string[];
        effectivePermissions: string[];
        supervisorProgramCount?: number;
        canAssignPlatformManage?: boolean;
        disabled?: boolean;
        errors?: Record<string, string[] | string | undefined>;
        description?: string | null;
        showEffectiveReadout?: boolean;
    } = $props();

    let search = $state("");

    const GROUP_ORDER = [
        "platform",
        "admin",
        "auth",
        "staff",
        "profile",
        "public",
        "programs",
        "kiosk",
        "other",
    ];

    function segment(p: string): string {
        const i = p.indexOf(".");
        return i === -1 ? "other" : p.slice(0, i);
    }

    function groupTitle(seg: string): string {
        if (seg === "other") return "Other";
        return seg.charAt(0).toUpperCase() + seg.slice(1);
    }

    let filtered = $derived(
        assignablePermissions.filter((p) => {
            const q = search.trim().toLowerCase();
            if (!q) return true;
            return p.toLowerCase().includes(q);
        }),
    );

    let grouped = $derived.by(() => {
        /** @type Record<string, string[]> */
        const m: Record<string, string[]> = {};
        for (const p of filtered) {
            const seg = segment(p);
            if (!m[seg]) m[seg] = [];
            m[seg].push(p);
        }
        for (const k of Object.keys(m)) {
            m[k].sort((a, b) => a.localeCompare(b));
        }
        return m;
    });

    let sortedGroupKeys = $derived.by(() => {
        const keys = Object.keys(grouped);
        keys.sort((a, b) => {
            const ia = GROUP_ORDER.indexOf(a);
            const ib = GROUP_ORDER.indexOf(b);
            if (ia === -1 && ib === -1) return a.localeCompare(b);
            if (ia === -1) return 1;
            if (ib === -1) return -1;
            return ia - ib;
        });
        return keys;
    });

    function isSelected(name: string): boolean {
        return selected.includes(name);
    }

    function toggle(name: string): void {
        if (disabled) return;
        if (name === "platform.manage" && !canAssignPlatformManage) return;
        if (isSelected(name)) {
            selected = selected.filter((n) => n !== name);
        } else {
            selected = [...selected, name].sort((a, b) => a.localeCompare(b));
        }
    }

    function effectiveFromRole(name: string): boolean {
        return effectivePermissions.includes(name) && !selected.includes(name);
    }

    const errDirect = $derived(
        errors?.direct_permissions != null
            ? Array.isArray(errors.direct_permissions)
                ? errors.direct_permissions
                : [String(errors.direct_permissions)]
            : [],
    );
</script>

<div class="space-y-3">
    {#if description}
        <p class="text-sm text-surface-600 leading-relaxed">{description}</p>
    {:else}
        <p class="text-sm text-surface-600 leading-relaxed">
            <strong class="text-surface-800">Direct permissions</strong> are extra
            grants on top of the user’s <strong>role</strong>. Effective access is
            the union of role permissions and direct grants.
        </p>
    {/if}
    {#if supervisorProgramCount > 0}
        <p
            class="text-sm rounded-container border border-primary-200 bg-primary-50/80 text-surface-800 px-3 py-2"
            role="status"
        >
            This user is a <strong>program supervisor</strong> on
            {supervisorProgramCount}
            {supervisorProgramCount === 1 ? "program" : "programs"}. They also
            receive supervisor-related permissions from that assignment (e.g.
            dashboard and authorization tools), in addition to what you set
            here.
        </p>
    {/if}

    {#if showEffectiveReadout}
        <div>
            <span class="label-text font-medium text-surface-950"
                >Effective permissions (read-only)</span
            >
            <p class="text-xs text-surface-500 mt-0.5 mb-1">
                Snapshot from last load; direct grant changes apply after you save.
            </p>
            <ul
                class="mt-1 max-h-32 overflow-y-auto rounded-container border border-surface-200 bg-surface-50/80 px-3 py-2 text-sm font-mono text-surface-800"
                aria-label="Effective permission names"
            >
                {#if effectivePermissions.length === 0}
                    <li class="text-surface-500">—</li>
                {:else}
                    {#each effectivePermissions as name (name)}
                        <li class="leading-relaxed">
                            {name}
                            {#if selected.includes(name)}
                                <span class="text-surface-500 text-xs ml-1"
                                    >(direct grant)</span
                                >
                            {:else if effectiveFromRole(name)}
                                <span class="text-surface-500 text-xs ml-1"
                                    >(from role)</span
                                >
                            {/if}
                        </li>
                    {/each}
                {/if}
            </ul>
        </div>
    {/if}

    <div class="form-control">
        <label class="label" for="perm-search"
            ><span class="label-text font-medium"
                >Search assignable permissions</span
            ></label
        >
        <input
            id="perm-search"
            type="search"
            class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
            placeholder="Filter by name (e.g. dashboard, platform)…"
            bind:value={search}
            autocomplete="off"
            {disabled}
        />
        <span class="label-text-alt mt-1"
            >Permissions are grouped by prefix. Use search when the list grows.</span
        >
    </div>

    {#if errDirect.length > 0}
        <div
            class="rounded-container border border-error-200 bg-error-50 text-error-800 text-sm px-3 py-2"
            role="alert"
        >
            {#each errDirect as msg (msg)}
                <p>{msg}</p>
            {/each}
        </div>
    {/if}

    <div
        class="max-h-56 overflow-y-auto rounded-container border border-surface-200 bg-surface-50/50 p-2 space-y-3"
    >
        {#each sortedGroupKeys as seg (seg)}
            <div>
                <p
                    class="text-xs font-semibold uppercase tracking-wide text-surface-500 px-1 mb-1"
                >
                    {groupTitle(seg)}
                </p>
                <ul class="space-y-1">
                    {#each grouped[seg] ?? [] as name (name)}
                        <li>
                            <label
                                class="flex items-start gap-2 cursor-pointer rounded px-1 py-1 hover:bg-surface-100/80 {disabled
                                    ? 'opacity-60 cursor-not-allowed'
                                    : ''}"
                            >
                                <input
                                    type="checkbox"
                                    class="checkbox mt-0.5"
                                    checked={isSelected(name)}
                                    disabled={disabled ||
                                        (name === 'platform.manage' &&
                                            !canAssignPlatformManage)}
                                    onchange={() => toggle(name)}
                                />
                                <span class="text-sm font-mono text-surface-900 break-all"
                                    >{name}</span
                                >
                            </label>
                        </li>
                    {/each}
                </ul>
            </div>
        {/each}
    </div>
</div>
