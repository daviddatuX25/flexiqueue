/**
 * Reusable Svelte action: drag-to-scroll via ScrollBooster.
 * Use on the viewport element (scroll container); content is first child or options.content.
 *
 * @example
 *   use:scrollBooster
 *   use:scrollBooster={{ direction: 'vertical' }}
 *   use:scrollBooster={{ content: '.inner', cursorGrab: false }}
 *
 * @param {HTMLElement} node - Viewport element (scroll container)
 * @param {{ content?: Element | string; direction?: 'horizontal' | 'vertical' | 'all'; scrollMode?: 'native' | 'transform'; bounce?: boolean; cursorGrab?: boolean; [key: string]: unknown }} [options] - Optional config
 * @returns {{ destroy: () => void }}
 */
import ScrollBooster from 'scrollbooster';

const VIEWPORT_CLASS = 'scroll-booster-viewport';
const GRABBING_CLASS = 'scroll-booster-grabbing';

export function scrollBooster(node, options = {}) {
	const content =
		options.content === undefined
			? node.firstElementChild
			: typeof options.content === 'string'
				? node.querySelector(options.content)
				: options.content;

	if (!content || !(content instanceof Element)) {
		return { destroy: () => {} };
	}

	const cursorGrab = options.cursorGrab !== false;
	const { content: _contentOpt, cursorGrab: _cursorGrabOpt, ...restOptions } = options;

	const scrollBoosterOptions = {
		viewport: node,
		content,
		direction: restOptions.direction ?? 'horizontal',
		scrollMode: restOptions.scrollMode ?? 'native',
		bounce: restOptions.bounce !== false,
		textSelection: false,
		...restOptions,
	};

	if (cursorGrab) {
		node.classList.add(VIEWPORT_CLASS);
		const userOnPointerDown = scrollBoosterOptions.onPointerDown;
		const userOnPointerUp = scrollBoosterOptions.onPointerUp;
		scrollBoosterOptions.onPointerDown = (...args) => {
			node.classList.add(GRABBING_CLASS);
			userOnPointerDown?.(...args);
		};
		scrollBoosterOptions.onPointerUp = (...args) => {
			node.classList.remove(GRABBING_CLASS);
			userOnPointerUp?.(...args);
		};
	}

	const sb = new ScrollBooster(scrollBoosterOptions);

	return {
		destroy() {
			sb.destroy();
			node.classList.remove(VIEWPORT_CLASS, GRABBING_CLASS);
		},
	};
}

export default scrollBooster;
