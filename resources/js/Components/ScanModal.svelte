<script lang="ts">
    /**
     * Reusable scan modal: HID barcode input + camera QR scanner, same layout everywhere.
     * When modal opens and HID is enabled, the HID input is focused first so hardware scanners work immediately.
     * Use in: mobile footer (approve QR), staff triage, public triage, display board.
     */
    import { tick } from "svelte";
    import Modal from "./Modal.svelte";
    import QrScanner from "./QrScanner.svelte";
    import HidScannerStatus from "./HidScannerStatus.svelte";
    import type { Snippet } from "svelte";

    let {
        open = false,
        title = "Scan QR",
        description = "",
        onClose = () => {},
        onScan = (_value: string) => {},
        allowHid = true,
        allowCamera = true,
        soundOnScan = false,
        wide = true,
        /** Use inputmode="none" for HID to suppress mobile keyboard when appropriate (e.g. hardware scanner only). */
        inputModeNone = true,
        /** Optional content between camera and HID status (e.g. countdown + Extend). */
        extra,
    }: {
        open?: boolean;
        title?: string;
        description?: string;
        onClose?: () => void;
        onScan?: (decodedText: string) => void;
        allowHid?: boolean;
        allowCamera?: boolean;
        soundOnScan?: boolean;
        wide?: boolean;
        inputModeNone?: boolean;
        extra?: Snippet;
    } = $props();

    let hidValue = $state("");
    let hidInputEl = $state<HTMLInputElement | null>(null);
    let hidFocusLost = $state(false);

    /** When modal opens and HID is enabled: reset focus state so status shows "waiting for scan", then focus HID. */
    $effect(() => {
        if (!open || !allowHid) return;
        hidFocusLost = false;
        tick().then(() => {
            requestAnimationFrame(() => {
                hidInputEl?.focus();
            });
        });
    });

    function onHidKeydown(e: KeyboardEvent) {
        if (e.key !== "Enter") return;
        const raw = hidValue.trim();
        if (!raw) return;
        e.preventDefault();
        onScan(raw);
        hidValue = "";
        hidInputEl?.focus();
    }
</script>

<Modal {open} {title} {onClose} {wide}>
    <div class="flex flex-col gap-3 w-full min-w-[20rem] mx-auto text-surface-950">
        {#if description}
            <p class="text-sm text-surface-950/70">{description}</p>
        {/if}
        {#if allowHid}
            <input
                type="text"
                autocomplete="off"
                inputmode={inputModeNone ? "none" : "text"}
                aria-label="Barcode scanner input"
                class="sr-only"
                bind:value={hidValue}
                bind:this={hidInputEl}
                onkeydown={onHidKeydown}
                onfocus={() => (hidFocusLost = false)}
                onblur={() => (hidFocusLost = true)}
            />
        {/if}
        {#if allowCamera}
            <QrScanner
                active={open}
                cameraOnly={true}
                onScan={(decoded) => onScan(decoded)}
                soundOnScan={soundOnScan}
            />
        {/if}
        {#if extra}
            {@render extra()}
        {/if}
        {#if allowHid}
            <HidScannerStatus
                focused={!hidFocusLost}
                onRequestFocus={() => {
                    hidFocusLost = false;
                    hidInputEl?.focus();
                }}
            />
        {/if}
        <button
            type="button"
            class="btn preset-tonal w-full py-3 touch-target-h"
            onclick={onClose}
        >
            Cancel
        </button>
    </div>
</Modal>
