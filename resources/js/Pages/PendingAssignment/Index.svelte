<script lang="ts">
    /**
     * H5 / ONB-5: staff with pending_assignment land here until an admin assigns a station or clears the flag.
     */
    import AuthLayout from "../../Layouts/AuthLayout.svelte";
    import AppBackground from "../../Components/AppBackground.svelte";
    import { router } from "@inertiajs/svelte";
    import { Link } from "@inertiajs/svelte";

    const heroImageUrl = "/images/mswdo_tagudin.jpg";

    function logout() {
        router.post("/logout");
    }
</script>

<svelte:head>
    <title>Awaiting assignment — FlexiQueue</title>
</svelte:head>

<AuthLayout>
    <AppBackground heroImageUrl={heroImageUrl} />
    <main
        class="min-h-screen flex flex-col items-center justify-center p-6 relative"
    >
        <div
            class="card bg-surface-50 rounded-container shadow-xl max-w-md w-full p-6 space-y-4"
        >
            <h1 class="text-xl font-bold text-surface-950 text-center">
                Awaiting assignment
            </h1>
            <p class="text-surface-700 text-sm leading-relaxed text-center">
                Your account is active, but an administrator must assign you to
                a station (or clear onboarding hold) before you can use the
                queue, station board, and triage.
            </p>
            <p class="text-surface-600 text-xs leading-relaxed text-center">
                You can open Profile to manage recovery Gmail or account
                security. When you are assigned, refresh this page or sign in
                again.
            </p>
            <div class="flex flex-col sm:flex-row gap-2 justify-center pt-2">
                <Link
                    href="/profile"
                    class="btn preset-filled-primary-500 shadow-sm justify-center"
                    >Profile</Link
                >
                <button
                    type="button"
                    class="btn preset-tonal justify-center"
                    onclick={logout}
                >
                    Sign out
                </button>
            </div>
        </div>
    </main>
</AuthLayout>
