<script lang="ts">
	import type { Snippet } from "svelte";

	interface Props {
		/** Extra classes for the outer container (e.g. margins, responsive visibility). */
		class?: string;
		/** Extra classes for the table element (e.g. text size). */
		tableClass?: string;
		/** Use smaller text for denser tables. */
		compact?: boolean;
		/** Optional header snippet: rendered inside <thead>. */
		head?: Snippet;
		/** Optional body snippet: rendered inside <tbody>. */
		body?: Snippet;
	}

	let {
		class: containerClass = "",
		tableClass = "",
		compact = false,
		head,
		body,
	}: Props = $props();
</script>

<!-- Shared admin data table shell. Usage:
	<AdminTable class="mt-6 hidden md:block">
		{#snippet head()}
			<tr>...</tr>
		{/snippet}
		{#snippet body()}
			{#each rows as row}
				<tr>...</tr>
			{/each}
		{/snippet}
	</AdminTable>
-->
<div
	class={[
		"table-container",
		"w-full",
		containerClass,
	]
		.filter(Boolean)
		.join(" ")}
>
	<table
		class={[
			"table",
			"fq-admin-table",
			"w-full",
			compact ? "text-sm" : "text-base",
			tableClass,
		]
			.filter(Boolean)
			.join(" ")}
	>
		<thead>
			{@render head?.()}
		</thead>
		<tbody>
			{@render body?.()}
		</tbody>
	</table>
</div>

