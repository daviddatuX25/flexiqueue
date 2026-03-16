<script lang="ts">
    import { usePage, router } from "@inertiajs/svelte";
    import { Link } from "@inertiajs/svelte";
    import AuthLayout from "../Layouts/AuthLayout.svelte";
    import AppBackground from "../Components/AppBackground.svelte";
    import ThemeToggle from "../Components/ThemeToggle.svelte";
    import Modal from "../Components/Modal.svelte";
    import { fade } from "svelte/transition";

    const KNOWN_SITES_COOKIE = "known_sites";
    const KNOWN_SITES_MAX_AGE_DAYS = 365;

    type KnownSite = { slug: string; name: string };

    function getKnownSites(): KnownSite[] {
        if (typeof document === "undefined") return [];
        const raw = document.cookie
            .split("; ")
            .find((row) => row.startsWith(KNOWN_SITES_COOKIE + "="));
        if (!raw) return [];
        const value = decodeURIComponent(raw.slice(KNOWN_SITES_COOKIE.length + 1).trim());
        try {
            const parsed = JSON.parse(value);
            if (!Array.isArray(parsed)) return [];
            return parsed.filter((x): x is KnownSite => x && typeof x.slug === "string");
        } catch {
            return [];
        }
    }

    function setKnownSites(sites: KnownSite[]) {
        if (typeof document === "undefined") return;
        const value = encodeURIComponent(JSON.stringify(sites));
        document.cookie = `${KNOWN_SITES_COOKIE}=${value}; path=/; max-age=${KNOWN_SITES_MAX_AGE_DAYS * 86400}; SameSite=Lax`;
    }

    function addKnownSite(slug: string, name: string) {
        const sites = getKnownSites();
        if (sites.some((s) => s.slug === slug)) return;
        setKnownSites([...sites, { slug, name }]);
    }

    interface Props {
        dashboardRoute?: string | null;
        dashboardLabel?: string | null;
        roleBadge?: string | null;
        appName?: string;
        appEnv?: string;
        appVersion?: string;
        default_site_slug?: string | null;
        heroImageUrl?: string;
    }

    const FEATURES = [
        {
            title: "Programs & Tracks",
            desc: "Organize services into programs with multiple tracks and steps.",
            tag: "admin",
            icon: "M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10",
        },
        {
            title: "Token Management",
            desc: "Batch-generate, print, and manage tokens with QR codes or PINs.",
            tag: "admin",
            icon: "M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z",
        },
        {
            title: "Live Station Serving",
            desc: "Call, serve, transfer, complete from the station interface. Real-time via WebSocket.",
            tag: "staff",
            icon: "M13 10V3L4 14h7v7l9-11h-7z",
        },
        {
            title: "Triage & Binding",
            desc: "Bind tokens to sessions by scan or lookup. Route clients to the right track.",
            tag: "staff",
            icon: "M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4",
        },
        {
            title: "Queue Displays",
            desc: "Public queue board and per-station boards. No refresh, no login required.",
            tag: "public",
            icon: "M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z",
        },
        {
            title: "Logs & Audit",
            desc: "Immutable audit trail. Every action logged for COA compliance.",
            tag: "admin",
            icon: "M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z",
        },
    ] as const;

    const ADMIN_JOURNEY = [
        {
            num: "1",
            title: "Program",
            desc: "Create the program for your office/unit.",
        },
        {
            num: "2",
            title: "Stations",
            desc: "Define stations/steps for the workflow.",
        },
        { num: "3", title: "Staff", desc: "Create staff accounts and roles." },
        { num: "4", title: "Assign", desc: "Assign staff to their stations." },
        {
            num: "5",
            title: "Tokens",
            desc: "Generate/print tokens and configure TTS.",
        },
        {
            num: "6",
            title: "Launch",
            desc: "Activate the program and open triage/display.",
        },
        {
            num: "7",
            title: "Monitor",
            desc: "Use dashboard, logs, and analytics to track throughput.",
        },
    ] as const;

    const STAFF_JOURNEY = [
        {
            num: "1",
            title: "Bind",
            desc: "Scan/lookup a token, choose a track, start a session.",
        },
        {
            num: "2",
            title: "Queue",
            desc: "See who is waiting for your station (and priorities).",
        },
        {
            num: "3",
            title: "Call",
            desc: "Call the next token; the display updates instantly.",
        },
        {
            num: "4",
            title: "Serve",
            desc: "Provide service at your station for the active session.",
        },
        {
            num: "5",
            title: "Finish",
            desc: "Transfer, complete, or mark no-show when needed.",
        },
    ] as const;

    const CLIENT_JOURNEY = [
        {
            num: "1",
            title: "Check in",
            desc: "Scan/enter your token at Start Triage, or visit the triage desk.",
        },
        {
            num: "2",
            title: "Choose",
            desc: "Select the service/track you need (staff can assist if needed).",
        },
        {
            num: "3",
            title: "Wait",
            desc: "Sit in the waiting area and watch the display for your token.",
        },
        {
            num: "4",
            title: "Proceed",
            desc: "When called, go to the station shown on the board.",
        },
        {
            num: "5",
            title: "Complete",
            desc: "Finish your request, or follow transfer instructions to the next station.",
        },
    ] as const;

    const PROBLEMS_SOLUTIONS = [
        {
            problem:
                "Long Queues: Manual ticketing causes crowding and long wait times.",
            solution: "Track-Based Management: Organizes sessions by services.",
            icon: "M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z",
        },
        {
            problem:
                "Perceived Favoritism: Verbal coordination leads to perceived unfairness.",
            solution: "Privacy-Preserving Tokens: Assures total fairness.",
            icon: "M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3",
        },
        {
            problem: "Staff Burnout: Manual handling increases workload.",
            solution: "Automated Workflow: Seamless state transitions.",
            icon: "M13 10V3L4 14h7v7l9-11h-7z",
        },
        {
            problem:
                "Privacy Issues: Calling out real names compromises dignity.",
            solution: "Alias System: Safe and anonymous voice announcements.",
            icon: "M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z",
        },
    ] as const;

    const TECH_FEATURES = [
        {
            title: "Local Deployment",
            desc: "Runs on Orange Pi with SQLite. Offline-first mDNS.",
        },
        {
            title: "Immutable Audit",
            desc: "Actions logged for uncompromising COA compliance.",
        },
        {
            title: "Multilingual TTS",
            desc: "Announcements natively in English, Filipino, and Ilocano.",
        },
        {
            title: "Remote Access",
            desc: "ZeroTier connectivity for true distributed access.",
        },
    ] as const;

    const SDGS = [
        {
            id: "16",
            title: "Peace, Justice & Institutions",
            desc: "Transparent logs.",
            color: "bg-blue-600",
        },
        {
            id: "11",
            title: "Reduced Inequalities",
            desc: "Accessibility and privacy.",
            color: "bg-orange-500",
        },
        {
            id: "9",
            title: "Industry & Innovation",
            desc: "Digital transformation.",
            color: "bg-orange-600",
        },
    ] as const;

    let {
        dashboardRoute = null,
        dashboardLabel = null,
        roleBadge = null,
        appName = "FlexiQueue",
        appEnv = "production",
        appVersion = "1.0.0-dev",
        default_site_slug = null,
        heroImageUrl = "/images/mswdo_tagudin.jpg",
    }: Props = $props();

    const page = usePage();
    const authUser = $derived(
        ($page?.props as { auth?: { user?: { name?: string } } })?.auth?.user ??
            null,
    );
    const csrfToken = $derived(
        ($page?.props as { csrf_token?: string })?.csrf_token ?? "",
    );

    /** Per public-site plan: global stats from /api/home-stats, poll every 30s. */
    let homeStats = $state<{ served_count: number; session_hours: number } | null>(null);

    function fetchHomeStats() {
        fetch("/api/home-stats", { credentials: "same-origin" })
            .then((res) => (res.ok ? res.json() : null))
            .then((data) => {
                if (data && typeof data.served_count === "number" && typeof data.session_hours === "number") {
                    homeStats = { served_count: data.served_count, session_hours: data.session_hours };
                }
            })
            .catch(() => {});
    }

    $effect(() => {
        if (typeof fetch === "undefined") return;
        fetchHomeStats();
        const id = setInterval(fetchHomeStats, 30000);
        return () => clearInterval(id);
    });

    /** When URL has site_key_for + program_key_prompt and user already has that site, redirect to site landing so program key modal shows there. */
    $effect(() => {
        if (typeof window === "undefined") return;
        const params = new URLSearchParams(window.location.search);
        const siteSlug = params.get("site_key_for");
        const programSlug = params.get("program_key_prompt");
        if (siteSlug && programSlug && getKnownSites().some((s) => s.slug === siteSlug)) {
            router.visit("/site/" + siteSlug + "?program_key_prompt=" + encodeURIComponent(programSlug));
        }
    });

    /** Per public-site plan: site key entry and site picker modals; resolve Monitor your queue destination. */
    let showKeyEntryModal = $state(false);
    let showSitePickerModal = $state(false);
    let keyInput = $state("");
    let keyEntryError = $state("");
    let keySubmitting = $state(false);
    let knownSitesList = $state<KnownSite[]>([]);

    function openMonitorQueue() {
        const sites = getKnownSites();
        if (sites.length === 0) {
            keyEntryError = "";
            keyInput = "";
            showKeyEntryModal = true;
        } else if (sites.length === 1) {
            router.visit("/site/" + sites[0].slug);
        } else {
            knownSitesList = sites;
            showSitePickerModal = true;
        }
    }

    function closeKeyEntry() {
        showKeyEntryModal = false;
        keyEntryError = "";
        keyInput = "";
    }

    async function submitSiteKey() {
        const key = keyInput.trim();
        if (!key) return;
        keyEntryError = "";
        keySubmitting = true;
        try {
            const res = await fetch("/api/public/site-key", {
                method: "POST",
                headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": csrfToken, "X-Requested-With": "XMLHttpRequest" },
                body: JSON.stringify({ key }),
                credentials: "same-origin",
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.slug) {
                addKnownSite(data.slug, data.name || data.slug);
                closeKeyEntry();
                const url = typeof window !== "undefined" ? new URL(window.location.href) : null;
                const programKeyPrompt = url?.searchParams.get("program_key_prompt");
                const target = programKeyPrompt
                    ? "/site/" + data.slug + "?program_key_prompt=" + encodeURIComponent(programKeyPrompt)
                    : "/site/" + data.slug;
                router.visit(target);
            } else {
                keyEntryError = "Invalid key. Please try again.";
            }
        } catch {
            keyEntryError = "Something went wrong. Please try again.";
        } finally {
            keySubmitting = false;
        }
    }

    function openAddSiteKey() {
        showSitePickerModal = false;
        keyEntryError = "";
        keyInput = "";
        showKeyEntryModal = true;
    }

    const initialJourneyTab =
        roleBadge === "admin" ||
        roleBadge === "supervisor" ||
        roleBadge === "super_admin"
            ? "admin"
            : roleBadge === "staff"
              ? "staff"
              : "client";
    let activeJourneyTab = $state<"admin" | "staff" | "client">(
        initialJourneyTab,
    );
</script>

<svelte:head>
    <title>{appName}</title>
</svelte:head>

<AuthLayout>
    <AppBackground {heroImageUrl} />

    <div
        class="relative min-h-screen text-surface-900 font-sans selection:bg-primary-500 selection:text-white pb-10 overflow-hidden"
    >
        <!-- Top bar: theme toggle always visible; auth strip when logged in -->
        <div class="fixed top-4 right-4 z-[60] flex items-center gap-2" in:fade>
            {#if authUser}
                <div
                    class="home-nav-strip flex items-center gap-x-3 rounded-full pl-5 pr-4 py-2.5 text-sm font-medium"
                >
                    {#if dashboardRoute && dashboardLabel}
                        <Link
                            href={dashboardRoute}
                            class="text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 transition-colors font-bold"
                        >
                            {dashboardLabel}
                        </Link>
                        <div
                            class="h-4 w-px bg-surface-300 dark:bg-surface-600"
                            aria-hidden="true"
                        ></div>
                    {/if}
                    <Link
                        href="/profile"
                        class="flex items-center gap-2 hover:opacity-80 transition-opacity"
                        title="Go to Profile"
                    >
                        <div
                            class="w-7 h-7 rounded-full bg-primary-100 dark:bg-primary-900/50 flex items-center justify-center text-primary-600 dark:text-primary-400 font-bold overflow-hidden shadow-sm border border-primary-200 dark:border-primary-700"
                        >
                            {#if authUser?.avatar}
                                <img
                                    src={authUser.avatar}
                                    alt={authUser.name}
                                    class="w-full h-full object-cover"
                                />
                            {:else}
                                <span class="text-xs"
                                    >{authUser.name?.charAt(0) ?? "S"}</span
                                >
                            {/if}
                        </div>
                        <span
                            class="hidden sm:inline font-bold text-surface-800 dark:text-surface-600"
                            >{authUser.name ?? "Staff"}</span
                        >
                        {#if roleBadge}
                            <span
                                class="hidden sm:inline px-2 py-0.5 rounded-full bg-primary-100 dark:bg-primary-900/50 text-primary-700 dark:text-primary-300 text-[10px] uppercase font-bold ml-1"
                                >{roleBadge}</span
                            >
                        {/if}
                    </Link>
                    <div
                        class="h-4 w-px bg-surface-300 dark:bg-surface-600"
                        aria-hidden="true"
                    ></div>
                    <ThemeToggle />
                </div>
            {:else}
                <div
                    class="home-nav-strip flex items-center gap-x-3 rounded-full pl-4 pr-4 py-2.5 text-sm font-medium"
                >
                    <Link
                        href="/login"
                        class="hover:text-primary-500 dark:hover:text-primary-400 transition-colors font-medium"
                        >Login</Link
                    >
                    <div
                        class="h-4 w-px bg-surface-300 dark:bg-surface-600"
                        aria-hidden="true"
                    ></div>
                    <ThemeToggle />
                </div>
            {/if}
        </div>

        <!-- 1. Stunning Hero Section -->
        <section
            class="relative isolate z-0 pt-32 pb-24 lg:pt-48 lg:pb-36 px-6 overflow-hidden"
        >
            <!-- The artsy tech grid overlay -->
            <div class="absolute inset-0 -z-10 bg-transparent">
                <!-- Artsy Tech Grid Pattern -->
                <div
                    class="absolute inset-0 bg-[linear-gradient(to_right,#80808012_1px,transparent_1px),linear-gradient(to_bottom,#80808012_1px,transparent_1px)] bg-[size:24px_24px]"
                ></div>

                <!-- Blurred vibrant orbs -->
                <div
                    class="absolute top-0 right-[20%] w-[30rem] h-[30rem] bg-primary-300/30 rounded-full blur-3xl mix-blend-multiply opacity-50"
                ></div>
                <div
                    class="absolute bottom-[10%] left-[10%] w-[40rem] h-[30rem] bg-emerald-300/20 rounded-full blur-3xl mix-blend-multiply opacity-50"
                ></div>
                <div
                    class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[50rem] h-[20rem] bg-teal-200/20 rounded-full blur-3xl mix-blend-multiply opacity-50"
                ></div>
            </div>

            <div class="max-w-5xl mx-auto text-center z-10 relative">
                <div
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/60 backdrop-blur-md md:backdrop-blur-none md:bg-white/80 border border-primary-200 text-primary-600 text-[10px] md:text-xs font-bold tracking-[0.2em] uppercase shadow-sm mb-10"
                    in:fade={{ delay: 100 }}
                >
                    <span class="relative flex h-2 w-2">
                        <span
                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary-400 opacity-75"
                        ></span>
                        <span
                            class="relative inline-flex rounded-full h-2 w-2 bg-primary-500"
                        ></span>
                    </span>
                    <span class="text-primary-600 dark:text-surface-300">
                        Next-Generation Institutional Operations
                    </span>
                </div>

                <h1
                    class="text-5xl md:text-7xl lg:text-8xl font-black text-surface-900 dark:text-white tracking-tight leading-[1.05] mb-8"
                    in:fade={{ delay: 200 }}
                >
                    <span class="relative inline-block">
                        Zero Friction.
                        <!-- Decorative tech line under text -->
                        <div
                            class="absolute -bottom-2 left-0 w-full h-1 bg-gradient-to-r from-transparent via-surface-300 to-transparent opacity-50"
                        ></div>
                    </span>
                    <br class="hidden md:block" />
                    <span
                        class="text-transparent bg-clip-text bg-gradient-to-r from-primary-600 via-emerald-500 to-teal-500 drop-shadow-sm"
                        >Total Clarity.</span
                    >
                </h1>

                <p
                    class="text-lg md:text-xl text-surface-600 max-w-2xl mx-auto font-medium leading-relaxed mb-12"
                    in:fade={{ delay: 300 }}
                >
                    FlexiQueue is the definitive queue lifecycle engine. From
                    secure token generation to live station triage and an
                    immutable audit trail.
                </p>

                <div
                    class="flex flex-wrap justify-center gap-6 mb-12"
                    in:fade={{ delay: 400 }}
                >
                    <div
                        class="bg-white/60 dark:bg-slate-800/60 backdrop-blur-xl md:backdrop-blur-none md:bg-white/80 md:dark:bg-slate-800/80 border border-surface-200/50 dark:border-slate-700/50 rounded-2xl px-8 py-5 shadow-lg"
                    >
                        <div
                            class="text-5xl font-black text-surface-800 dark:text-white"
                        >
                            {homeStats ? homeStats.served_count : "—"}
                        </div>
                        <div
                            class="text-xs font-bold text-surface-500 dark:text-surface-300 uppercase tracking-widest mt-2"
                        >
                            People served
                        </div>
                    </div>
                    <div
                        class="bg-white/60 dark:bg-slate-800/60 backdrop-blur-xl md:backdrop-blur-none md:bg-white/80 md:dark:bg-slate-800/80 border border-surface-200/50 dark:border-slate-700/50 rounded-2xl px-8 py-5 shadow-lg"
                    >
                        <div
                            class="text-5xl font-black text-primary-600 dark:text-primary-400"
                        >
                            {homeStats ? homeStats.session_hours : "—"}
                        </div>
                        <div
                            class="text-xs font-bold text-surface-500 dark:text-surface-300 uppercase tracking-widest mt-2"
                        >
                            Program hours
                        </div>
                    </div>
                </div>

                <div
                    class="flex flex-col sm:flex-row items-center justify-center gap-5"
                    in:fade={{ delay: 500 }}
                >
                    <button
                        type="button"
                        onclick={openMonitorQueue}
                        class="w-full sm:w-auto px-8 py-4 rounded-xl bg-surface-900 dark:bg-primary-600 hover:bg-surface-800 dark:hover:bg-primary-500 text-white font-bold text-lg shadow-[0_4px_20px_rgba(0,0,0,0.15)] hover:-translate-y-1 transition-all duration-300 flex items-center justify-center gap-3 relative overflow-hidden group"
                    >
                        <div
                            class="absolute inset-0 bg-white/10 translate-y-full group-hover:translate-y-0 transition-transform duration-300"
                        ></div>
                        Monitor your queue
                        <svg
                            class="w-5 h-5 relative"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            ><path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M14 5l7 7m0 0l-7 7m7-7H3"
                            ></path></svg
                        >
                    </button>
                    {#if !authUser}
                        <Link
                            href="/login"
                            class="w-full sm:w-auto px-8 py-4 rounded-xl bg-white/80 dark:bg-slate-800/80 backdrop-blur-md md:backdrop-blur-none md:bg-white/95 md:dark:bg-slate-800/95 hover:bg-white dark:hover:bg-slate-700 border border-surface-200 dark:border-slate-700 !text-surface-800 dark:!text-white font-bold text-lg hover:-translate-y-1 transition-all duration-300 shadow-sm"
                        >
                            Login
                        </Link>
                    {/if}
                </div>
            </div>

            <!-- Techy Angular Bottom Divider -->
            <div
                class="absolute bottom-0 left-0 w-full overflow-hidden leading-none text-white drop-shadow-sm"
            >
                <svg
                    class="relative block w-full h-[30px] md:h-[60px]"
                    viewBox="0 0 1200 120"
                    preserveAspectRatio="none"
                >
                    <!-- Geometric overlay lines -->
                    <path
                        d="M0,120 L1200,120 L1200,60 L900,60 L850,110 L350,110 L300,60 L0,60 Z"
                        fill="currentColor"
                        opacity="0.4"
                    ></path>
                    <path
                        d="M0,120 L1200,120 L1200,90 L920,90 L880,120 L320,120 L280,90 L0,90 Z"
                        fill="currentColor"
                    ></path>
                </svg>
            </div>
        </section>

        <!-- 2. The Problem & Solution (The Hook) -->
        <section
            class="py-20 px-6 relative z-10 bg-white/40 dark:bg-transparent"
        >
            <div class="max-w-7xl mx-auto">
                <div class="text-center mb-16">
                    <h2
                        class="text-sm font-black tracking-widest text-primary-600 dark:text-surface-600 uppercase mb-3"
                    >
                        Why FlexiQueue?
                    </h2>
                    <p
                        class="text-3xl md:text-5xl font-extrabold text-surface-900 dark:text-primary-400 tracking-tight"
                    >
                        Chaos, Refactored.
                    </p>
                </div>

                <div class="grid lg:grid-cols-2 gap-8">
                    {#each PROBLEMS_SOLUTIONS as item}
                        <div
                            class="group relative home-card-bg rounded-3xl p-8 md:p-10 shadow-lg border border-surface-200 dark:border-surface-700 hover:shadow-2xl hover:-translate-y-2 transition-all duration-500 overflow-hidden"
                        >
                            <div
                                class="absolute -right-20 -top-20 w-64 h-64 bg-primary-100 dark:bg-primary-900/30 rounded-full group-hover:scale-150 group-hover:opacity-50 transition-transform duration-700 ease-in-out -z-10"
                            ></div>
                            <div class="flex items-start gap-5">
                                <div
                                    class="w-14 h-14 rounded-2xl bg-primary-100 dark:bg-primary-900/50 text-primary-600 dark:text-primary-400 shadow-inner flex items-center justify-center flex-shrink-0 group-hover:scale-110 transition-transform duration-300"
                                >
                                    <svg
                                        class="w-7 h-7"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                        ><path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d={item.icon}
                                        ></path></svg
                                    >
                                </div>
                                <div>
                                    <h3
                                        class="home-card-label text-xs font-black uppercase tracking-widest mb-2"
                                    >
                                        The Challenge
                                    </h3>
                                    <p
                                        class="home-card-body font-bold text-lg mb-6 leading-snug"
                                    >
                                        {item.problem}
                                    </p>
                                    <div
                                        class="w-full h-px bg-surface-200 dark:bg-surface-600 mb-6"
                                    ></div>
                                    <h3
                                        class="text-xs font-black uppercase tracking-widest text-primary-600 dark:text-primary-400 mb-2"
                                    >
                                        The Solution
                                    </h3>
                                    <p
                                        class="home-card-accent font-bold text-xl leading-snug"
                                    >
                                        {item.solution}
                                    </p>
                                </div>
                            </div>
                        </div>
                    {/each}
                </div>
            </div>
        </section>

        <!-- 3. What it does / Core Features (The Value) -->
        <section
            class="py-24 px-6 bg-surface-100/40 dark:bg-transparent relative"
        >
            <div class="max-w-7xl mx-auto">
                <div class="text-center mb-16">
                    <h2
                        class="text-sm font-black tracking-widest text-primary-600 dark:text-primary-400 uppercase mb-3"
                    >
                        Platform Capabilities
                    </h2>
                    <p
                        class="text-3xl md:text-5xl font-extrabold text-surface-900 tracking-tight"
                    >
                        The Complete Queue Engine
                    </p>
                </div>

                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    {#each FEATURES as feature}
                        <div
                            class="home-card-bg rounded-3xl p-8 border border-surface-200 dark:border-surface-700 hover:shadow-xl hover:border-primary-300 dark:hover:border-primary-600 transition-all duration-300 group"
                        >
                            <div
                                class="w-12 h-12 rounded-xl bg-surface-100 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 text-surface-500 dark:text-surface-400 group-hover:text-primary-600 dark:group-hover:text-primary-400 group-hover:border-primary-300 dark:group-hover:border-primary-600 shadow-sm flex items-center justify-center mb-6 transition-colors"
                            >
                                <svg
                                    class="w-6 h-6"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                    ><path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d={feature.icon}
                                    ></path></svg
                                >
                            </div>
                            <h3 class="text-xl font-bold text-surface-900 mb-3">
                                {feature.title}
                            </h3>
                            <p
                                class="text-surface-600 dark:text-surface-400 mb-6 font-medium leading-relaxed"
                            >
                                {feature.desc}
                            </p>
                            <span
                                class="inline-flex items-center px-3 py-1 rounded-full bg-surface-200 dark:bg-surface-700 text-surface-600 dark:text-surface-400 text-[10px] font-bold uppercase tracking-widest"
                                >{feature.tag}</span
                            >
                        </div>
                    {/each}
                </div>
            </div>
        </section>

        <!-- 4. How our system works (The Journey) -->
        <section class="py-24 px-6 bg-surface-50/40 dark:bg-transparent">
            <div class="max-w-6xl mx-auto">
                <div class="text-center mb-12">
                    <h2
                        class="text-sm font-black tracking-widest text-primary-600 dark:text-primary-400 uppercase mb-3"
                    >
                        Lifecycle Journeys
                    </h2>
                    <p
                        class="text-3xl md:text-5xl font-extrabold text-surface-900 tracking-tight"
                    >
                        Streamlined Flows
                    </p>
                </div>

                <!-- Tabs for Journeys -->
                <div class="flex justify-center mb-12">
                    <div
                        class="bg-surface-200 dark:bg-slate-800/50 p-1.5 rounded-2xl inline-flex shadow-inner"
                    >
                        <button
                            class="px-8 py-3 rounded-xl transition-all font-bold text-sm {activeJourneyTab ===
                            'client'
                                ? 'home-card-bg text-primary-600 dark:text-primary-400 shadow-md'
                                : 'text-surface-500 dark:text-surface-400 hover:text-surface-800 dark:hover:text-surface-200'}"
                            onclick={() => (activeJourneyTab = "client")}
                        >
                            Client Day
                        </button>
                        <button
                            class="px-8 py-3 rounded-xl transition-all font-bold text-sm {activeJourneyTab ===
                            'staff'
                                ? 'home-card-bg text-primary-600 dark:text-primary-400 shadow-md'
                                : 'text-surface-500 dark:text-surface-400 hover:text-surface-800 dark:hover:text-surface-200'}"
                            onclick={() => (activeJourneyTab = "staff")}
                        >
                            Staff Day
                        </button>
                        <button
                            class="px-8 py-3 rounded-xl transition-all font-bold text-sm {activeJourneyTab ===
                            'admin'
                                ? 'home-card-bg text-primary-600 dark:text-primary-400 shadow-md'
                                : 'text-surface-500 dark:text-surface-400 hover:text-surface-800 dark:hover:text-surface-200'}"
                            onclick={() => (activeJourneyTab = "admin")}
                        >
                            Admin Setup
                        </button>
                    </div>
                </div>

                <div
                    class="home-card-bg rounded-[2rem] p-10 md:p-14 shadow-2xl border border-surface-200 dark:border-surface-700 overflow-x-auto custom-scrollbar"
                >
                    {#if activeJourneyTab === "client"}
                        <div
                            class="flex items-start justify-between min-w-[700px] relative"
                            in:fade
                        >
                            <div
                                class="absolute top-[32px] left-0 w-full h-1 bg-surface-200 dark:bg-surface-600"
                            ></div>
                            {#each CLIENT_JOURNEY as step}
                                <div
                                    class="relative flex flex-col items-center text-center w-36 group"
                                >
                                    <div
                                        class="w-16 h-16 rounded-2xl home-card-bg border-4 border-surface-200 dark:border-surface-600 group-hover:border-primary-500 group-hover:shadow-lg text-surface-500 dark:text-surface-400 group-hover:text-primary-600 dark:group-hover:text-primary-400 flex items-center justify-center font-black text-2xl mb-6 transition-all duration-300 z-10"
                                    >
                                        {step.num}
                                    </div>
                                    <h4
                                        class="font-bold text-surface-900 mb-2 leading-tight"
                                    >
                                        {step.title}
                                    </h4>
                                    <p
                                        class="text-xs text-surface-500 dark:text-surface-400 font-medium leading-relaxed"
                                    >
                                        {step.desc}
                                    </p>
                                </div>
                            {/each}
                        </div>
                    {/if}

                    {#if activeJourneyTab === "staff"}
                        <div
                            class="flex items-start justify-between min-w-[700px] relative"
                            in:fade
                        >
                            <div
                                class="absolute top-[32px] left-0 w-full h-1 bg-surface-200 dark:bg-surface-600"
                            ></div>
                            {#each STAFF_JOURNEY as step}
                                <div
                                    class="relative flex flex-col items-center text-center w-36 group"
                                >
                                    <div
                                        class="w-16 h-16 rounded-2xl home-card-bg border-4 border-surface-200 dark:border-surface-600 group-hover:border-primary-500 group-hover:shadow-lg text-surface-500 dark:text-surface-400 group-hover:text-primary-600 dark:group-hover:text-primary-400 flex items-center justify-center font-black text-2xl mb-6 transition-all duration-300 z-10"
                                    >
                                        {step.num}
                                    </div>
                                    <h4
                                        class="font-bold text-surface-900 mb-2 leading-tight"
                                    >
                                        {step.title}
                                    </h4>
                                    <p
                                        class="text-xs text-surface-500 dark:text-surface-400 font-medium leading-relaxed"
                                    >
                                        {step.desc}
                                    </p>
                                </div>
                            {/each}
                        </div>
                    {/if}

                    {#if activeJourneyTab === "admin"}
                        <div
                            class="flex items-start justify-between min-w-[900px] relative"
                            in:fade
                        >
                            <div
                                class="absolute top-[32px] left-0 w-full h-1 bg-surface-200 dark:bg-surface-600"
                            ></div>
                            {#each ADMIN_JOURNEY as step}
                                <div
                                    class="relative flex flex-col items-center text-center w-32 group"
                                >
                                    <div
                                        class="w-16 h-16 rounded-2xl home-card-bg border-4 border-surface-200 dark:border-surface-600 group-hover:border-primary-500 group-hover:shadow-lg text-surface-500 dark:text-surface-400 group-hover:text-primary-600 dark:group-hover:text-primary-400 flex items-center justify-center font-black text-2xl mb-6 transition-all duration-300 z-10"
                                    >
                                        {step.num}
                                    </div>
                                    <h4
                                        class="font-bold text-surface-900 mb-2 leading-tight"
                                    >
                                        {step.title}
                                    </h4>
                                    <p
                                        class="text-xs text-surface-500 dark:text-surface-400 font-medium leading-relaxed"
                                    >
                                        {step.desc}
                                    </p>
                                </div>
                            {/each}
                        </div>
                    {/if}
                </div>
            </div>
        </section>

        <!-- 5. Technical Excellence (dark block in both themes) -->
        <section
            class="py-24 px-6 home-tech-bg text-white relative border-y border-surface-800/50"
        >
            <div
                class="absolute top-0 right-0 w-[500px] h-[500px] bg-primary-500/10 rounded-full blur-[100px] pointer-events-none"
            ></div>
            <div class="max-w-7xl mx-auto">
                <div class="flex flex-col lg:flex-row gap-16 items-center">
                    <div class="flex-1 space-y-6">
                        <h2
                            class="text-sm font-black tracking-widest text-primary-400 uppercase"
                        >
                            Under The Hood
                        </h2>
                        <p
                            class="text-4xl md:text-5xl font-extrabold leading-tight tracking-tight"
                        >
                            Built for Mission Critical Contexts.
                        </p>
                        <p
                            class="text-surface-300 dark:text-surface-400 text-lg leading-relaxed font-medium pb-6"
                        >
                            Infrastructure engineered to withstand remote
                            environments, guarantee transparency, and provide
                            seamless interaction without reliance on stable
                            internet.
                        </p>
                    </div>
                    <div
                        class="flex-1 grid sm:grid-cols-2 gap-6 w-full relative z-10"
                    >
                        {#each TECH_FEATURES as feature}
                            <div
                                class="home-tech-card rounded-3xl p-8 transition-colors shadow-2xl"
                            >
                                <h4 class="font-bold text-lg mb-3 text-white">
                                    {feature.title}
                                </h4>
                                <p
                                    class="text-sm text-surface-300 dark:text-surface-400 font-medium leading-relaxed"
                                >
                                    {feature.desc}
                                </p>
                            </div>
                        {/each}
                    </div>
                </div>
            </div>
        </section>

        <!-- 6. SDG Alignment -->
        <section class="py-24 px-6 bg-surface-100/40 dark:bg-transparent">
            <div class="max-w-6xl mx-auto text-center">
                <h2
                    class="text-sm font-black tracking-widest text-primary-600 dark:text-primary-400 uppercase mb-3"
                >
                    Community First
                </h2>
                <p
                    class="text-3xl md:text-5xl font-extrabold text-surface-900 tracking-tight mb-16"
                >
                    Global Goals Alignment
                </p>

                <div class="grid md:grid-cols-3 gap-8 text-left">
                    {#each SDGS as sdg}
                        <div
                            class="home-card-bg rounded-3xl p-10 shadow-xl border border-surface-200 dark:border-surface-700 hover:-translate-y-2 hover:shadow-2xl transition-all duration-300"
                        >
                            <div
                                class="w-20 h-20 rounded-[1.2rem] {sdg.color} text-white flex items-center justify-center font-black text-3xl mb-8 shadow-lg"
                            >
                                {sdg.id}
                            </div>
                            <h3
                                class="text-2xl font-bold text-surface-900 mb-4"
                            >
                                {sdg.title}
                            </h3>
                            <p
                                class="text-surface-600 dark:text-surface-400 font-medium leading-relaxed"
                            >
                                {sdg.desc}
                            </p>
                        </div>
                    {/each}
                </div>
            </div>
        </section>

        <!-- 7. Public Access Points -->
        <section class="py-32 px-6">
            <div class="max-w-6xl mx-auto">
                <div
                    class="bg-gradient-to-br from-primary-700 via-primary-600 to-emerald-700 rounded-[3rem] p-10 md:p-20 text-white shadow-2xl overflow-hidden relative"
                >
                    <!-- Geometric decoration -->
                    <div
                        class="absolute -bottom-[20%] -right-[10%] w-[80%] h-[150%] bg-white/5 rotate-12 -z-10 blur-xl"
                    ></div>

                    <div
                        class="relative z-10 grid lg:grid-cols-5 gap-16 items-center w-full"
                    >
                        <div class="lg:col-span-2 space-y-6">
                            <h2
                                class="text-4xl md:text-5xl font-black tracking-tight leading-tight"
                            >
                                Ready to check in?
                            </h2>
                            <p
                                class="text-primary-100 text-lg font-medium leading-relaxed"
                            >
                                No login required for clients. Start your
                                journey or view the live queue display instantly
                                on any device.
                            </p>
                        </div>
                        <div class="lg:col-span-3 grid sm:grid-cols-2 gap-6">
                            <button
                                type="button"
                                onclick={openMonitorQueue}
                                class="bg-white/10 hover:bg-white/20 backdrop-blur-xl md:backdrop-blur-none md:bg-white/15 border border-white/20 p-8 rounded-[2rem] transition-all duration-300 hover:scale-[1.03] active:scale-95 text-center flex flex-col items-center gap-4 w-full"
                            >
                                <svg
                                    class="w-10 h-10 text-primary-200"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                    ><path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                                    ></path></svg
                                >
                                <div>
                                    <h4 class="font-bold text-white text-xl">
                                        Monitor your queue
                                    </h4>
                                    <p
                                        class="text-xs font-bold text-primary-200 mt-2 uppercase tracking-widest"
                                    >
                                        Live full view (Beta)
                                    </p>
                                </div>
                            </button>

                            <Link
                                href="/display/station/1"
                                    class="bg-white/10 hover:bg-white/20 backdrop-blur-xl md:backdrop-blur-none md:bg-white/15 border border-white/20 p-8 rounded-[2rem] transition-all duration-300 hover:scale-[1.03] active:scale-95 text-center flex flex-col items-center gap-4"
                                >
                                    <svg
                                        class="w-10 h-10 text-primary-200"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                        ><path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"
                                        ></path></svg
                                    >
                                    <div>
                                        <h4
                                            class="font-bold text-white text-xl"
                                        >
                                            Station Board
                                        </h4>
                                        <p
                                            class="text-xs font-bold text-primary-200 mt-2 uppercase tracking-widest"
                                        >
                                            Single station view
                                        </p>
                                    </div>
                                </Link>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- 8. Project Overview / Bottom Credits -->
        <div class="px-6 pb-16">
            <div class="max-w-6xl mx-auto">
                <div
                    class="home-card-bg rounded-3xl p-8 md:p-12 shadow-md border border-surface-200 dark:border-surface-700 flex flex-col md:flex-row items-center justify-between gap-8"
                >
                    <div class="max-w-lg space-y-3 align-middle">
                        <h4
                            class="text-xs font-black tracking-widest text-surface-500 dark:text-surface-400 uppercase"
                        >
                            Project Overview
                        </h4>
                        <p class="text-surface-900 font-bold md:text-lg">
                            FlexiQueue: A Universal Queue Management System for
                            Multi-service Government Operations
                        </p>
                        <p
                            class="text-surface-600 dark:text-surface-400 font-medium"
                        >
                            Ilocos Sur Polytechnic State College, Tagudin Campus<br
                            />
                            College of Arts and Sciences
                        </p>
                    </div>
                    <div
                        class="hidden md:block w-px h-16 bg-surface-200 dark:bg-surface-600"
                        aria-hidden="true"
                    ></div>
                    <div class="text-left md:text-right space-y-3">
                        <h4
                            class="text-xs font-black tracking-widest text-surface-500 dark:text-surface-400 uppercase"
                        >
                            Developed By
                        </h4>
                        <p
                            class="text-primary-600 dark:text-primary-400 font-bold md:text-lg"
                        >
                            David Datu N. Sarmiento
                        </p>
                        <div
                            class="flex flex-col items-start md:items-end gap-3 mt-1"
                        >
                            <span
                                class="inline-flex items-center px-3 py-1 rounded-md bg-surface-200 dark:bg-surface-700 text-surface-600 dark:text-surface-400 text-[10px] font-bold uppercase tracking-widest"
                            >
                                v{appVersion} · Standalone Offline-Focused Server
                                via Orange Pi
                            </span>

                            <div
                                class="flex flex-col items-start md:items-end gap-1"
                            >
                                <span
                                    class="inline-flex items-center px-3 py-1 rounded-md bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 text-[10px] font-bold uppercase tracking-widest border border-primary-200 dark:border-primary-700"
                                >
                                    Currently Developing: v2.0.0-dev · Edge-Node
                                    Server
                                </span>
                                <span
                                    class="text-[10px] text-surface-500 dark:text-surface-400 font-medium max-w-sm text-left md:text-right leading-tight"
                                >
                                    Future feature: Seamless deployment between
                                    Orange Pi as an edge node with easy central
                                    access.
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tiny Footer Links -->
        <footer
            class="text-center pb-8 border-t border-surface-200 dark:border-surface-700 pt-8 mt-10"
        >
            <div
                class="flex flex-wrap items-center justify-center gap-6 text-sm font-bold text-surface-500 dark:text-surface-400"
            >
                <span>&copy; {new Date().getFullYear()} {appName}</span>
                <span
                    class="text-surface-400 dark:text-surface-500"
                    aria-hidden="true">·</span
                >
                <Link
                    href="/login"
                    class="hover:text-primary-600 dark:hover:text-primary-400 transition-colors uppercase tracking-wide text-xs"
                    >Login</Link
                >
                <span
                    class="text-surface-400 dark:text-surface-500"
                    aria-hidden="true">·</span
                >
                <Link
                    href="/display"
                    class="hover:text-primary-600 dark:hover:text-primary-400 transition-colors uppercase tracking-wide text-xs"
                    >Queue Display</Link
                >
            </div>
        </footer>
    </div>

    <!-- Site key entry: per public-site plan -->
    <Modal
        open={showKeyEntryModal}
        title="Enter site key"
        onClose={closeKeyEntry}
    >
        <form
            onsubmit={(e) => {
                e.preventDefault();
                submitSiteKey();
            }}
            class="space-y-4"
        >
            <p class="text-sm text-surface-600 dark:text-slate-400">
                Enter the site key provided by your venue to access the queue display.
            </p>
            <input
                type="text"
                bind:value={keyInput}
                placeholder="e.g. TAGUDIN8"
                class="input w-full"
                autocomplete="off"
                disabled={keySubmitting}
            />
            {#if keyEntryError}
                <p class="text-sm text-red-600 dark:text-red-400">{keyEntryError}</p>
            {/if}
            <div class="flex gap-2 justify-end">
                <button type="button" onclick={closeKeyEntry} class="btn variant-ghost">Cancel</button>
                <button type="submit" class="btn" disabled={keySubmitting}>
                    {keySubmitting ? "Checking…" : "Continue"}
                </button>
            </div>
        </form>
    </Modal>

    <!-- Site picker: when 2+ known sites -->
    <Modal
        open={showSitePickerModal}
        title="Choose a site"
        onClose={() => (showSitePickerModal = false)}
    >
        <div class="space-y-4">
            <p class="text-sm text-surface-600 dark:text-slate-400">
                Select a site to view its queue, or add another site key.
            </p>
            <ul class="space-y-2">
                {#each knownSitesList as site (site.slug)}
                    <li>
                        <Link
                            href="/site/{site.slug}"
                            class="btn preset-tonal flex w-full justify-start"
                        >
                            {site.name}
                        </Link>
                    </li>
                {/each}
            </ul>
            <button type="button" onclick={openAddSiteKey} class="btn variant-outline w-full">
                Enter another site key
            </button>
        </div>
    </Modal>
</AuthLayout>
