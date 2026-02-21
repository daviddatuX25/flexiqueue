<script lang="ts">
	/**
	 * UserAvatar — reusable avatar with custom photo or initials/Lucide User fallback.
	 * Per plan: Profile Avatar, Staff Display.
	 */
	import { User } from 'lucide-svelte';

	interface UserLike {
		name?: string;
		avatar_url?: string | null;
	}

	let {
		user = null,
		size = 'md',
	}: {
		user?: UserLike | null;
		size?: 'sm' | 'md' | 'lg';
	} = $props();

	let imageError = $state(false);
	const showImage = $derived(!!(user?.avatar_url && !imageError));

	const initials = $derived.by(() => {
		const n = user?.name?.trim();
		if (!n) return '?';
		const parts = n.split(/\s+/);
		if (parts.length >= 2) {
			return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
		}
		return n.charAt(0).toUpperCase();
	});

	/** Consistent background hue from name hash */
	function bgFromName(name: string | undefined): string {
		if (!name) return 'bg-primary-500';
		let h = 0;
		for (let i = 0; i < name.length; i++) h = (h << 5) - h + name.charCodeAt(i);
		const hue = Math.abs(h % 360);
		return `hsl(${hue}, 60%, 45%)`;
	}

	const sizeClasses = $derived(
		size === 'sm'
			? 'h-8 w-8 text-xs'
			: size === 'lg'
				? 'h-12 w-12 text-base'
				: 'h-10 w-10 text-sm',
	);

	function handleImageError() {
		imageError = true;
	}
</script>

{#if !user}
	<div
		class="rounded-full bg-surface-200 text-surface-500 flex items-center justify-center shrink-0 {sizeClasses}"
		role="img"
		aria-hidden="true"
	>
		<User class="h-1/2 w-1/2" />
	</div>
{:else if showImage}
	<img
		src={user.avatar_url!}
		alt=""
		class="rounded-full object-cover shrink-0 {sizeClasses}"
		onerror={handleImageError}
	/>
{:else}
	<div
		class="rounded-full text-white flex items-center justify-center font-bold shrink-0 {sizeClasses}"
		style="background-color: {bgFromName(user.name)}"
		role="img"
		aria-label={user.name ? `Avatar for ${user.name}` : 'User avatar'}
	>
		{initials}
	</div>
{/if}
