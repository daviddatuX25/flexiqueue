<script lang="ts">
    import { Link } from "@inertiajs/svelte";
    import { Settings, Users, FileText, Zap } from "lucide-svelte";
    import type { DashboardStats } from "../../types/dashboard";

    let { stats }: { stats: DashboardStats | null } = $props();
</script>

<div class="space-y-4 h-full flex flex-col">
    <div class="card bg-surface-50 rounded-container elevation-card flex-1">
        <div class="card-body p-5 space-y-6">
            <h2
                class="card-title text-base flex items-center gap-2 border-b border-surface-100 pb-3"
            >
                <Zap class="h-4 w-4 text-warning-500" />
                Quick Actions
            </h2>

            <div>
                <p
                    class="text-xs font-semibold uppercase tracking-wider text-surface-500 mb-3 ml-1"
                >
                    Program & Setup
                </p>
                <Link
                    href="/admin/programs"
                    class="btn preset-tonal-surface w-full justify-start gap-3 hover:preset-tonal-primary transition-colors py-3"
                >
                    <Settings class="h-4 w-4 shrink-0 text-surface-600" />
                    <span class="font-medium">Manage Program</span>
                </Link>
            </div>

            <div>
                <p
                    class="text-xs font-semibold uppercase tracking-wider text-surface-500 mb-3 ml-1"
                >
                    People & Audit log
                </p>
                <div class="flex flex-col gap-3">
                    <Link
                        href="/admin/users"
                        class="btn preset-tonal-surface w-full justify-start gap-3 hover:preset-tonal-primary transition-colors py-3"
                    >
                        <Users class="h-4 w-4 shrink-0 text-surface-600" />
                        <span class="font-medium">Manage Staff</span>
                    </Link>
                    <Link
                        href="/admin/logs"
                        class="btn preset-tonal-surface w-full justify-start gap-3 hover:preset-tonal-primary transition-colors py-3"
                    >
                        <FileText class="h-4 w-4 shrink-0 text-surface-600" />
                        <span class="font-medium">View audit log</span>
                    </Link>
                </div>
            </div>
        </div>
    </div>

    <!-- Staff Online -->
    {#if stats}
        <div class="card bg-surface-50 rounded-container elevation-card mt-4">
            <div class="card-body p-5 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div
                        class="p-3 bg-secondary-100 text-secondary-600 rounded-xl"
                    >
                        <Users class="h-6 w-6" />
                    </div>
                    <div>
                        <p
                            class="text-sm font-semibold uppercase tracking-wider text-surface-500 mb-0.5"
                        >
                            Staff Online
                        </p>
                        <p class="text-xs text-surface-400">
                            Available and assigned
                        </p>
                    </div>
                </div>
                <div class="text-3xl font-bold text-surface-900">
                    {stats.staff_online}
                </div>
            </div>
        </div>
    {/if}
</div>
