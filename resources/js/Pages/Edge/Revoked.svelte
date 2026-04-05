<script lang="ts">
    // No props — this is a terminal state page.
    import AuthLayout from '../../Layouts/AuthLayout.svelte';
</script>

<svelte:head>
    <title>Device Revoked — FlexiQueue</title>
</svelte:head>

<AuthLayout>
    <div class="min-h-screen flex flex-col items-center justify-center p-6">
        <div class="flex flex-col items-center text-center max-w-sm">
            <div class="rounded-full bg-error-100 dark:bg-error-900/30 p-4 mb-6">
                <!-- Lock icon -->
                <svg
                    class="w-10 h-10 text-error-600 dark:text-error-400"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.5"
                    stroke="currentColor"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75
                           m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75
                           a2.25 2.25 0 0 0-2.25-2.25H6.75
                           a2.25 2.25 0 0 0-2.25 2.25v6.75
                           a2.25 2.25 0 0 0 2.25 2.25Z"
                    />
                </svg>
            </div>

            <h1 class="text-2xl font-semibold text-surface-900 dark:text-surface-100 mb-3">
                Device Revoked
            </h1>

            <p class="text-surface-600 dark:text-surface-400">
                This device has been revoked by your administrator.
                Please contact them to re-pair this device with a new pairing code.
            </p>
        </div>
    </div>
</AuthLayout>
