<script lang="ts">
  import IdNumberInput from './IdNumberInput.svelte';
  /**
   * TriageClientBinder
   *
   * Staff-only identity binding panel for Triage/Index.svelte.
   *
   * Valid mode transitions (coarse-grained):
   * - 'idle' →
   *     'scanning' | 'lookup_in_progress' | 'name_search_form'
   * - 'scanning' →
   *     'idle' | 'lookup_in_progress'
   * - 'lookup_in_progress' →
   *     'lookup_match_existing' | 'lookup_not_found' | 'lookup_ambiguous'
   * - 'lookup_match_existing' →
   *     'binding_ready' | 'idle' | 'name_search_form' | 'lookup_in_progress'
   * - 'lookup_ambiguous' →
   *     'lookup_in_progress' (retry with chosen type) | 'idle'
   * - 'lookup_not_found' →
   *     'create_client_form' | 'name_search_form' | 'idle'
   * - 'name_search_form' →
   *     'name_search_in_progress' | 'idle'
   * - 'name_search_in_progress' →
   *     'name_search_results' | 'name_search_no_results'
   * - 'name_search_results' →
   *     'attach_id_confirm' | 'binding_ready' | 'name_search_form' | 'idle'
   * - 'name_search_no_results' →
   *     'create_client_form' | 'name_search_form' | 'idle'
   * - 'create_client_form' →
   *     'create_client_submitting' | 'idle'
   * - 'create_client_submitting' →
   *     'binding_ready' | 'create_client_error'
   * - 'create_client_error' →
   *     'create_client_form' | 'idle' | 'lookup_in_progress'
   * - 'attach_id_confirm' →
   *     'attach_id_submitting' | 'idle'
   * - 'attach_id_submitting' →
   *     'binding_ready' | 'attach_id_error'
   * - 'attach_id_error' →
   *     'attach_id_confirm' | 'idle'
   * - 'binding_ready' →
   *     'binding_confirmed' | 'idle'
   * - 'binding_confirmed' →
   *     'idle'
   * - 'skipped' →
   *     'idle'
   */

  import { get } from 'svelte/store';
  import { usePage } from '@inertiajs/svelte';
  import { Camera, RotateCcw } from 'lucide-svelte';
  import { toaster } from '../lib/toaster.js';
  import { isMobileTouch } from '../lib/displayHid.js';
  import Modal from './Modal.svelte';
  import QrScanner from './QrScanner.svelte';

  type BinderMode =
    | 'idle'
    | 'scanning'
    | 'lookup_in_progress'
    | 'lookup_match_existing'
    | 'lookup_ambiguous'
    | 'lookup_not_found'
    | 'name_search_form'
    | 'name_search_in_progress'
    | 'name_search_results'
    | 'name_search_no_results'
    | 'create_client_form'
    | 'create_client_submitting'
    | 'create_client_error'
    | 'attach_id_confirm'
    | 'attach_id_submitting'
    | 'attach_id_error'
    | 'binding_ready'
    | 'binding_confirmed'
    | 'skipped';

  type BindingSource = 'existing_id_document' | 'new_id_document' | 'name_search' | 'manual';

  export type ClientBindingPayload = {
    client_id: number;
    source: BindingSource;
    id_document_id?: number;
  };

  export type BinderStatus = 'idle' | 'bound' | 'skipped';

  export type BinderChangeEvent = {
    status: BinderStatus;
    client_binding: ClientBindingPayload | null;
    summary?: {
      client_name: string;
      birth_year: number | null;
      id_type?: string;
      id_last4?: string;
    };
  };

  export type BindingMode = 'disabled' | 'optional' | 'required';

  const MSG_SESSION_EXPIRED = 'Session expired. Please refresh and try again.';
  const MSG_NETWORK_ERROR = 'Network error. Please try again.';

  const page = usePage();
  function getCsrfToken(): string {
    const p = get(page);
    const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
    if (fromProps) return fromProps;
    const meta =
      typeof document !== 'undefined'
        ? (document.querySelector('meta[name=\"csrf-token\"]') as HTMLMetaElement | null)?.content
        : '';
    return meta ?? '';
  }

  async function api(
    method: string,
    url: string,
    body?: object
  ): Promise<{ ok: boolean; status: number; data?: any; message?: string; errors?: Record<string, string[]> }> {
    try {
      const res = await fetch(url, {
        method,
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': getCsrfToken(),
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        ...(body ? { body: JSON.stringify(body) } : {})
      });
      if (res.status === 419) {
        toaster.error({ title: MSG_SESSION_EXPIRED });
        return { ok: false, status: res.status, message: MSG_SESSION_EXPIRED };
      }
      const data = await res.json().catch(() => ({}));
      return {
        ok: res.ok,
        status: res.status,
        data,
        message: (data as { message?: string }).message,
        errors: (data as { errors?: Record<string, string[]> }).errors
      };
    } catch (e) {
      toaster.error({ title: MSG_NETWORK_ERROR });
      return { ok: false, status: 0, message: MSG_NETWORK_ERROR };
    }
  }

  const {
    bindingMode = 'disabled',
    allowHid = true,
    allowCamera = true,
    id_types = [],
    onBindingChange
  }: {
    bindingMode?: BindingMode;
    allowHid?: boolean;
    allowCamera?: boolean;
    /** ID type options for lookup; first is default. */
    id_types?: string[];
    onBindingChange?: (payload: BinderChangeEvent) => void;
  } = $props();

  // Coarse mode + flags
  let mode = $state<BinderMode>('idle');
  let isBusy = $state(false);
  let errorMessage = $state('');

  // ID entry (can originate from scan or manual entry). Auto = try all types; then confirmatory if ambiguous.
  const idTypeOptions = $derived(
    id_types?.length ? ['Auto', ...id_types.filter((t) => t !== 'Auto')] : ['Auto']
  );
  let idType = $state<string>('Auto');
  let idNumber = $state<string>('');

  // Name search
  let nameSearch = $state<string>('');
  let birthYearSearch = $state<string>('');

  // Results
  let lookupResult = $state<{
    client: { id: number; name: string; birth_year: number | null } | null;
    id_document?: { id: number; id_type: string; id_last4: string };
  } | null>(null);

  let nameSearchResults = $state<
    { id: number; name: string; birth_year: number | null; has_id_document?: boolean }[]
  >([]);
  let nameSearchMeta = $state<{ current_page: number; last_page: number; total: number; per_page: number } | null>(null);
  let nameSearchDebounceId = 0;
  const NAME_SEARCH_DEBOUNCE_MS = 250;
  const NAME_SEARCH_PER_PAGE = 3;

  let selectedClient = $state<{ id: number; name: string; birth_year: number | null } | null>(null);
  let selectedIdDocument = $state<{ id: number; id_type: string; id_last4: string } | null>(null);
  /** When lookup returns ambiguous, candidate ID types for confirmatory selection. */
  let ambiguousIdTypes = $state<string[]>([]);

  let clientBinding = $state<ClientBindingPayload | null>(null);
  let binderStatus = $state<BinderStatus>('idle');

  const isIdLookupMode = $derived(
    mode === 'idle' ||
      mode === 'scanning' ||
      mode === 'lookup_in_progress' ||
      mode === 'lookup_match_existing' ||
      mode === 'lookup_ambiguous' ||
      mode === 'lookup_not_found'
  );
  const isNameSearchMode = $derived(
    mode === 'name_search_form' ||
      mode === 'name_search_in_progress' ||
      mode === 'name_search_results' ||
      mode === 'name_search_no_results'
  );
  const showBinderNav = $derived(mode !== 'binding_confirmed');

  // Lookup by ID: HID barcode scanner (effective allowHid from parent = account && device)
  let binderHidInputEl = $state<HTMLInputElement | null>(null);
  let binderHidModalInputEl = $state<HTMLInputElement | null>(null);
  let binderHidValue = $state('');
  // Lookup by ID: camera scanner (effective allowCamera from parent)
  let showIdScanner = $state(false);
  let idScanHandled = $state(false);
  /** When true, show manual ID entry block (same format as public triage start binding). */
  let showManualIdEntry = $state(false);

  function emitChange() {
    onBindingChange?.({
      status: binderStatus,
      client_binding: clientBinding,
      summary: clientBinding && selectedClient
        ? {
            client_name: selectedClient.name,
            birth_year: selectedClient.birth_year,
            id_type: selectedIdDocument?.id_type,
            id_last4: selectedIdDocument?.id_last4
          }
        : undefined
    });
  }

  function resetBinderToIdle() {
    mode = 'idle';
    isBusy = false;
    errorMessage = '';
    idType = 'Auto';
    idNumber = '';
    nameSearch = '';
    birthYearSearch = '';
    lookupResult = null;
    nameSearchResults = [];
    nameSearchMeta = null;
    if (nameSearchDebounceId) {
      clearTimeout(nameSearchDebounceId);
      nameSearchDebounceId = 0;
    }
    binderHidValue = '';
    showIdScanner = false;
    idScanHandled = true;
    showManualIdEntry = false;
    selectedClient = null;
    selectedIdDocument = null;
    ambiguousIdTypes = [];
    clientBinding = null;
    binderStatus = 'idle';
    emitChange();
  }

  function goToSkipped() {
    // Optional mode only; parent still enforces required mode at submit level.
    mode = 'skipped';
    isBusy = false;
    errorMessage = '';
    clientBinding = null;
    binderStatus = 'skipped';
    emitChange();
  }

  function confirmBinding() {
    if (!clientBinding || !selectedClient) return;
    mode = 'binding_confirmed';
    binderStatus = 'bound';
    toaster.success({ title: 'Binding confirmed' });
    emitChange();
  }

  // ID lookup. When idType is 'Auto', omit id_type so backend tries all types; single match proceeds, ambiguous shows confirmatory selection.
  async function submitIdLookup() {
    const trimmedId = idNumber.trim();
    if (!trimmedId) {
      errorMessage = 'Enter an ID number first.';
      return;
    }
    errorMessage = '';
    isBusy = true;
    mode = 'lookup_in_progress';

    const body: { id_number: string; id_type?: string } = { id_number: trimmedId };
    if (idType !== 'Auto') {
      body.id_type = idType;
    }
    const { ok, status, data, message, errors } = await api('POST', '/api/clients/lookup-by-id', body);
    isBusy = false;

    if (!ok) {
      if (status === 422 && errors) {
        errorMessage = errors.id_number?.[0] || errors.id_type?.[0] || 'Validation failed.';
      } else {
        errorMessage = message || 'Lookup failed.';
      }
      mode = 'idle';
      return;
    }

    const matchStatus = (data as { match_status?: string }).match_status;
    if (matchStatus === 'ambiguous') {
      ambiguousIdTypes = (data as { id_types?: string[] }).id_types ?? [];
      if (ambiguousIdTypes.length) idType = ambiguousIdTypes[0];
      mode = 'lookup_ambiguous';
      return;
    }
    if (matchStatus === 'existing') {
      const client = (data as any).client as { id: number; name: string; birth_year: number | null };
      const id_document = (data as any).id_document as { id: number; id_type: string; id_last4: string };
      lookupResult = { client, id_document };
      selectedClient = client;
      selectedIdDocument = id_document;
      mode = 'lookup_match_existing';
    } else {
      lookupResult = { client: null };
      mode = 'lookup_not_found';
    }
  }

  // Name search: GET /api/clients/search, keydown-triggered with debounce. Per page = 3.
  // Only send birth_year when it's a valid full year (1900–2100) so partial typing doesn't trigger 422 or stuck state.
  const BIRTH_YEAR_MIN = 1900;
  const BIRTH_YEAR_MAX = 2100;

  async function runNameSearch(page = 1) {
    const name = nameSearch.trim();
    if (!name) {
      nameSearchResults = [];
      nameSearchMeta = null;
      mode = 'name_search_form';
      return;
    }
    errorMessage = '';
    isBusy = true;
    mode = 'name_search_in_progress';
    try {
      const birthYearVal = birthYearSearch.trim();
      const birthYearNum = birthYearVal ? Number(birthYearVal) : NaN;
      const birthYear =
        Number.isInteger(birthYearNum) && birthYearNum >= BIRTH_YEAR_MIN && birthYearNum <= BIRTH_YEAR_MAX
          ? birthYearNum
          : null;

      const params = new URLSearchParams({
        name,
        per_page: String(NAME_SEARCH_PER_PAGE),
        page: String(page),
      });
      if (birthYear != null) {
        params.set('birth_year', String(birthYear));
      }
      const { ok, data } = await api('GET', `/api/clients/search?${params.toString()}`);
      if (!ok || !data) {
        nameSearchResults = [];
        nameSearchMeta = null;
        mode = 'name_search_no_results';
        return;
      }
      const payload = data as { data?: { id: number; name: string; birth_year: number | null; has_id_document?: boolean }[]; meta?: { current_page: number; last_page: number; total: number; per_page: number } };
      nameSearchResults = payload.data ?? [];
      nameSearchMeta = payload.meta ?? null;
      mode = nameSearchResults.length > 0 ? 'name_search_results' : 'name_search_no_results';
    } finally {
      isBusy = false;
      if (mode === 'name_search_in_progress') {
        mode = 'name_search_no_results';
      }
    }
  }

  function scheduleNameSearch() {
    if (nameSearchDebounceId) clearTimeout(nameSearchDebounceId);
    nameSearchDebounceId = window.setTimeout(() => {
      nameSearchDebounceId = 0;
      runNameSearch(1);
    }, NAME_SEARCH_DEBOUNCE_MS);
  }

  function selectClientFromNameSearch(client: { id: number; name: string; birth_year: number | null }) {
    selectedClient = client;
    selectedIdDocument = null;
    clientBinding = {
      client_id: client.id,
      source: 'name_search',
      id_document_id: undefined,
    };
    mode = 'binding_ready';
    binderStatus = 'idle';
    emitChange();
  }

  function onBinderHidKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter') {
      e.preventDefault();
      const raw = binderHidValue.trim();
      if (raw) {
        showManualIdEntry = true;
        idNumber = raw;
        binderHidValue = '';
      }
    }
  }

  function handleModalHidScan() {
    const raw = binderHidValue.trim();
    if (!raw) return;
    showManualIdEntry = true;
    idNumber = raw.slice(0, 255);
    binderHidValue = '';
    showIdScanner = false;
    idScanHandled = true;
  }

  $effect(() => {
    if (!idTypeOptions.length) return;
    if (!idType || !idTypeOptions.includes(idType)) {
      idType = 'Auto';
    }
  });

  $effect(() => {
    if (mode !== 'idle' || !allowHid) return;
    const id = setInterval(() => {
      binderHidInputEl?.focus();
    }, 10000);
    return () => clearInterval(id);
  });

  $effect(() => {
    if (!showIdScanner || !allowHid) return;
    queueMicrotask(() => {
      requestAnimationFrame(() => {
        binderHidModalInputEl?.focus();
        if (document.activeElement !== binderHidModalInputEl) {
          binderHidInputEl?.focus();
        }
      });
    });
  });

  function selectExistingClientFromLookup() {
    if (!lookupResult?.client) return;
    selectedClient = lookupResult.client;
    selectedIdDocument = lookupResult.id_document ?? null;
    clientBinding = {
      client_id: selectedClient.id,
      source: lookupResult.id_document ? 'existing_id_document' : 'name_search',
      id_document_id: lookupResult.id_document?.id
    };
    mode = 'binding_ready';
    binderStatus = 'idle';
    emitChange();
  }

  // Create client with optional ID
  async function submitCreateClient() {
    const name = nameSearch.trim() || (selectedClient?.name ?? '');
    const birthYearValue = birthYearSearch.trim();
    if (!name) {
      errorMessage = 'Enter a name for the new client.';
      return;
    }
    const birthYear = birthYearValue ? Number(birthYearValue) : null;

    errorMessage = '';
    isBusy = true;
    mode = 'create_client_submitting';

    const body: any = {
      name,
      birth_year: birthYear
    };
    const trimmedId = idNumber.trim();
    if (trimmedId) {
      const docType = idType === 'Auto' && id_types?.length ? id_types[0] : idType;
      body.id_document = {
        id_type: docType,
        id_number: trimmedId
      };
    }

    const { ok, status, data, message, errors } = await api('POST', '/api/clients', body);
    isBusy = false;

    if (!ok) {
      if (status === 409 && (data as any)?.error_code === 'id_document_duplicate') {
        errorMessage =
          (data as any)?.hint ||
          (data as any)?.message ||
          'An ID document with this number already exists. Use lookup instead.';
        mode = 'create_client_error';
        return;
      }
      if (status === 422 && errors) {
        errorMessage =
          errors.name?.[0] ||
          errors.birth_year?.[0] ||
          errors['id_document.id_number']?.[0] ||
          errors['id_document.id_type']?.[0] ||
          'Validation failed.';
        mode = 'create_client_error';
        return;
      }
      errorMessage = message || 'Could not create client.';
      mode = 'create_client_error';
      return;
    }

    const client = (data as any).client as { id: number; name: string; birth_year: number | null };
    const id_document = (data as any).id_document as { id: number; id_type: string; id_last4: string } | undefined;
    selectedClient = client;
    selectedIdDocument = id_document ?? null;
    clientBinding = {
      client_id: client.id,
      source: id_document ? 'new_id_document' : 'manual',
      id_document_id: id_document?.id
    };
    mode = 'binding_ready';
    binderStatus = 'idle';
    emitChange();
  }

  // Attach ID to existing client
  async function submitAttachId() {
    if (!selectedClient) return;
    const trimmedId = idNumber.trim();
    if (!trimmedId) {
      errorMessage = 'Enter an ID number to attach.';
      return;
    }

    errorMessage = '';
    isBusy = true;
    mode = 'attach_id_submitting';

    const docType = idType === 'Auto' && id_types?.length ? id_types[0] : idType;
    const { ok, status, data, message, errors } = await api(
      'POST',
      `/api/clients/${encodeURIComponent(String(selectedClient.id))}/id-documents`,
      {
        id_type: docType,
        id_number: trimmedId
      }
    );
    isBusy = false;

    if (!ok) {
      if (status === 409 && (data as any)?.error_code === 'id_document_duplicate') {
        errorMessage = (data as any)?.message || 'An ID document with this number already exists.';
        mode = 'attach_id_error';
        return;
      }
      if (status === 422 && errors) {
        errorMessage = errors.id_number?.[0] || errors.id_type?.[0] || 'Validation failed.';
        mode = 'attach_id_error';
        return;
      }
      errorMessage = message || 'Could not attach ID.';
      mode = 'attach_id_error';
      return;
    }

    const id_document = (data as any).id_document as { id: number; id_type: string; id_last4: string };
    selectedIdDocument = id_document;
    clientBinding = {
      client_id: selectedClient.id,
      source: 'new_id_document',
      id_document_id: id_document.id
    };
    mode = 'binding_ready';
    binderStatus = 'idle';
    emitChange();
  }
</script>

{#if bindingMode !== 'disabled'}
  {#if mode === 'skipped'}
    <!-- Collapsed indicator when staff explicitly skipped in optional mode. -->
    <div
      class="rounded-container border border-surface-200 bg-surface-50 elevation-card p-4 flex items-center justify-between gap-3"
      data-testid="binder-skip-indicator"
    >
      <div class="flex flex-col">
        <p class="text-sm font-medium text-surface-950">Client identity binding skipped</p>
        <p class="text-xs text-surface-700">
          Optional for this program. You can still bind a client before confirming.
        </p>
      </div>
      <button
        type="button"
        class="btn preset-tonal text-sm touch-target-h"
        data-testid="binder-bind-now-button"
        onclick={resetBinderToIdle}
      >
        Bind now
      </button>
    </div>
  {:else}
    <div
      class="rounded-container border border-surface-200 bg-surface-50 elevation-card p-4 md:p-6 space-y-4"
      data-testid="triage-client-binder"
    >
      <div class="flex items-center justify-between gap-2">
        <div>
          <p class="text-sm font-semibold text-surface-950">Client identity</p>
          <p class="text-xs text-surface-700">
            {bindingMode === 'required'
							? 'Required: triage cannot be completed without binding to a client.'
							: 'Optional: recommended to bind to a client for better follow-up, but you can skip for this session.'}
          </p>
        </div>
        {#if bindingMode === 'optional' && binderStatus !== 'bound'}
          <button
            type="button"
            class="btn preset-tonal text-xs touch-target-h"
            data-testid="binder-skip-button"
            onclick={goToSkipped}
          >
            Skip
          </button>
        {/if}
      </div>

      {#if errorMessage}
        <div
          class="rounded-container border border-error-200 bg-error-50 px-3 py-2 text-xs text-error-800"
          data-testid="binder-error"
        >
          {errorMessage}
        </div>
      {/if}

      {#if showBinderNav}
        <div class="flex flex-wrap gap-2 mb-2" data-testid="binder-mode-nav">
          <button
            type="button"
            class={`btn flex-1 touch-target-h ${
              isIdLookupMode ? 'preset-filled-primary-500' : 'preset-tonal'
            }`}
            onclick={() => {
              mode = 'idle';
              errorMessage = '';
              nameSearch = '';
              birthYearSearch = '';
              nameSearchResults = [];
              nameSearchMeta = null;
            }}
            disabled={isBusy}
          >
            Lookup by ID
          </button>
          <button
            type="button"
            class={`btn flex-1 touch-target-h ${
              isNameSearchMode ? 'preset-filled-primary-500' : 'preset-tonal'
            }`}
            onclick={() => {
              mode = 'name_search_form';
            }}
            disabled={isBusy}
          >
            Search by name
          </button>
        </div>
      {/if}

      {#if mode === 'binding_confirmed' && clientBinding && selectedClient}
        <div
          class="flex items-center gap-3 rounded-container border border-surface-200 bg-surface-100 px-2 py-2"
          data-testid="binder-status-confirmed"
          role="status"
        >
          <button
            type="button"
            class="shrink-0 rounded-lg p-2 text-surface-600 hover:bg-surface-200 hover:text-surface-900 touch-target-h"
            aria-label="Change binding"
            onclick={resetBinderToIdle}
          >
            <RotateCcw size={20} />
          </button>
          <p class="text-sm text-surface-800 min-w-0 truncate">
            <span class="font-medium text-surface-950">{selectedClient.name}</span>
            {#if selectedIdDocument}
              <span class="text-surface-600"> · {selectedIdDocument.id_type} …{selectedIdDocument.id_last4}</span>
            {:else}
              <span class="text-surface-500"> · No ID attached</span>
            {/if}
          </p>
        </div>
      {:else if mode === 'binding_ready' && clientBinding && selectedClient}
        <div
          class="rounded-container border border-surface-200 bg-surface-50 pl-4 pr-4 py-3 flex flex-wrap items-center justify-between gap-3"
          data-testid="binder-status-ready"
        >
          <p class="text-sm text-surface-800 min-w-0">
            <span class="font-medium text-surface-950">{selectedClient.name}</span>
            <span class="text-surface-600"> ({selectedClient.birth_year ?? 'Year n/a'})</span>
            {#if selectedIdDocument}
              <span class="text-surface-600"> · {selectedIdDocument.id_type} …{selectedIdDocument.id_last4}</span>
            {:else}
              <span class="text-surface-500"> · No ID attached</span>
            {/if}
          </p>
          <button
            type="button"
            class="btn preset-filled-primary-500 touch-target-h shrink-0"
            data-testid="binder-confirm-binding-button"
            onclick={confirmBinding}
            disabled={isBusy}
          >
            Confirm binding
          </button>
        </div>
      {:else}
        <!-- Main state machine body -->
        {#if mode === 'lookup_match_existing' && lookupResult}
          <div class="space-y-3" data-testid="binder-found-match-confirmation">
            <p class="text-sm font-semibold text-surface-950">Found match</p>
            <div class="rounded-container border border-surface-200 bg-surface-100 px-3 py-3 space-y-1">
              <p class="text-xs font-medium text-surface-900">
                {lookupResult.client?.name}
              </p>
              <p class="text-xs text-surface-700">
                Birth year: {lookupResult.client?.birth_year ?? 'n/a'}
              </p>
              {#if lookupResult.id_document}
                <p class="text-xs text-surface-700">
                  {lookupResult.id_document.id_type} ending in {lookupResult.id_document.id_last4}
                </p>
              {/if}
            </div>
            <p class="text-xs text-surface-600">Is this the correct client?</p>
            <div class="flex flex-wrap gap-2">
              <button
                type="button"
                class="btn preset-filled-primary-500 touch-target-h flex-1"
                data-testid="binder-found-match-proceed"
                onclick={() => { selectExistingClientFromLookup(); confirmBinding(); }}
                disabled={isBusy}
              >
                Proceed
              </button>
              <button
                type="button"
                class="btn preset-tonal touch-target-h flex-1"
                data-testid="binder-found-match-report-not-me"
                onclick={resetBinderToIdle}
                disabled={isBusy}
              >
                Report as not me
              </button>
            </div>
          </div>
        {:else if mode === 'lookup_ambiguous'}
          <div class="space-y-3" data-testid="binder-lookup-ambiguous">
            <p class="text-sm font-medium text-surface-950">Multiple ID types match this number</p>
            <p class="text-xs text-surface-700">
              This ID could be one of the following. Please select the correct ID type and search again.
            </p>
            {#if ambiguousIdTypes.length}
              <label class="flex flex-col gap-1 text-xs">
                <span class="text-surface-700">ID type</span>
                <select
                  class="select select-theme input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  data-testid="binder-ambiguous-id-type-select"
                  bind:value={idType}
                  disabled={isBusy}
                  aria-label="ID type"
                >
                  {#each ambiguousIdTypes as type (type)}
                    <option value={type}>{type}</option>
                  {/each}
                </select>
              </label>
              <div class="flex flex-wrap gap-2">
                <button
                  type="button"
                  class="btn preset-filled-primary-500 touch-target-h flex-1"
                  data-testid="binder-ambiguous-search-again"
                  onclick={submitIdLookup}
                  disabled={isBusy}
                >
                  {isBusy ? 'Looking up…' : 'Search again with selected type'}
                </button>
                <button
                  type="button"
                  class="btn preset-tonal touch-target-h flex-1"
                  onclick={resetBinderToIdle}
                  disabled={isBusy}
                >
                  Try different ID
                </button>
              </div>
            {/if}
          </div>
        {:else if mode === 'lookup_not_found'}
          <div class="space-y-3">
            <p class="text-sm font-medium text-surface-950">No client with this ID</p>
            <p class="text-xs text-surface-700">
              You can create a new client with this ID, search by name, or try a different ID.
            </p>
            <div class="flex flex-wrap gap-2">
              <button
                type="button"
                class="btn preset-filled-primary-500 touch-target-h flex-1"
                onclick={() => {
                  if (idType === 'Auto' && id_types?.length) idType = id_types[0];
                  mode = 'create_client_form';
                }}
              >
                Create new client with this ID
              </button>
              <button
                type="button"
                class="btn preset-tonal touch-target-h flex-1"
                onclick={() => (mode = 'name_search_form')}
              >
                Search by name
              </button>
              <button
                type="button"
                class="btn preset-tonal touch-target-h flex-1"
                onclick={resetBinderToIdle}
              >
                Try different ID
              </button>
            </div>
          </div>
        {:else if mode === 'create_client_form' || mode === 'create_client_error'}
          <div class="space-y-3">
            <p class="text-sm font-medium text-surface-950">Create new client</p>
            <div class="space-y-2">
              <label class="flex flex-col gap-1 text-xs">
                <span class="text-surface-700">Name</span>
                <input
                  type="text"
                  class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  data-testid="binder-create-name-input"
                  bind:value={nameSearch}
                  placeholder="Full name"
                  disabled={isBusy}
                />
              </label>
              <label class="flex flex-col gap-1 text-xs">
                <span class="text-surface-700">Birth year (optional)</span>
                <input
                  type="number"
                  class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  data-testid="binder-create-birth-year-input"
                  bind:value={birthYearSearch}
                  placeholder="e.g. 1985"
                  disabled={isBusy}
                />
              </label>
              <label class="flex flex-col gap-1 text-xs">
                <span class="text-surface-700">ID type</span>
                {#if id_types?.length}
                  <select
                    class="select select-theme input rounded-container border border-surface-200 px-3 py-2 text-sm"
                    data-testid="binder-create-id-type-select"
                    bind:value={idType}
                    disabled={isBusy}
                    aria-label="ID type"
                  >
                    {#each id_types as type (type)}
                      <option value={type}>{type}</option>
                    {/each}
                  </select>
                {:else}
                  <input
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                    data-testid="binder-id-type-input"
                    bind:value={idType}
                    disabled={isBusy}
                  />
                {/if}
              </label>
              <div class="flex flex-col gap-1 text-xs">
                <span class="text-surface-700">ID number (optional)</span>
                <IdNumberInput
                  bind:value={idNumber}
                  placeholder="ID to bind (if available)"
                  disabled={isBusy}
                  showMaskToggle={true}
                  showScanButton={false}
                  inputClass="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  testId="binder-id-number-input"
                />
              </div>
            </div>
            <div class="flex gap-2">
              <button
                type="button"
                class="btn preset-tonal flex-1 touch-target-h"
                onclick={resetBinderToIdle}
                disabled={isBusy}
              >
                Cancel
              </button>
              <button
                type="button"
                class="btn preset-filled-primary-500 flex-1 touch-target-h"
                data-testid="binder-create-submit-button"
                onclick={submitCreateClient}
                disabled={isBusy}
              >
                {isBusy ? 'Creating…' : 'Create client'}
              </button>
            </div>
          </div>
        {:else if mode === 'attach_id_confirm' || mode === 'attach_id_error'}
          <div class="space-y-3">
            <p class="text-sm font-medium text-surface-950">Attach ID to client</p>
            {#if selectedClient}
              <p class="text-xs text-surface-800">
                {selectedClient.name} ({selectedClient.birth_year ?? 'Year n/a'})
              </p>
            {/if}
            <label class="flex flex-col gap-1 text-xs">
              <span class="text-surface-700">ID type</span>
              {#if id_types?.length}
                <select
                  class="select select-theme input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  data-testid="binder-attach-id-type-input"
                  bind:value={idType}
                  disabled={isBusy}
                  aria-label="ID type"
                >
                  {#each id_types as type (type)}
                    <option value={type}>{type}</option>
                  {/each}
                </select>
              {:else}
                <input
                  type="text"
                  class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  data-testid="binder-attach-id-type-input"
                  bind:value={idType}
                  disabled={isBusy}
                />
              {/if}
            </label>
            <div class="flex flex-col gap-1 text-xs">
              <span class="text-surface-700">ID number</span>
              <IdNumberInput
                bind:value={idNumber}
                disabled={isBusy}
                showMaskToggle={true}
                showScanButton={false}
                inputClass="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                testId="binder-attach-id-number-input"
              />
            </div>
            <div class="flex gap-2">
              <button
                type="button"
                class="btn preset-tonal flex-1 touch-target-h"
                onclick={resetBinderToIdle}
                disabled={isBusy}
              >
                Cancel
              </button>
              <button
                type="button"
                class="btn preset-filled-primary-500 flex-1 touch-target-h"
                data-testid="binder-attach-submit-button"
                onclick={submitAttachId}
                disabled={isBusy}
              >
                {isBusy ? 'Attaching…' : 'Attach ID'}
              </button>
            </div>
          </div>
        {:else if mode === 'name_search_form' || mode === 'name_search_no_results' || mode === 'name_search_results' || mode === 'name_search_in_progress'}
          <div class="space-y-3">
            <p class="text-sm font-medium text-surface-950">Search by name</p>
            <div class="space-y-2">
              <label class="flex flex-col gap-1 text-xs">
                <span class="text-surface-700">Name</span>
                <input
                  type="text"
                  class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  data-testid="binder-name-search-input"
                  bind:value={nameSearch}
                  onkeydown={scheduleNameSearch}
                  oninput={scheduleNameSearch}
                  placeholder="Full name"
                />
              </label>
              <label class="flex flex-col gap-1 text-xs">
                <span class="text-surface-700">Birth year (optional)</span>
                <input
                  type="number"
                  class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  data-testid="binder-name-search-birth-year-input"
                  bind:value={birthYearSearch}
                  onkeydown={scheduleNameSearch}
                  oninput={scheduleNameSearch}
                  placeholder="e.g. 1985"
                />
              </label>
            </div>
            {#if mode === 'name_search_in_progress'}
              <p class="text-xs text-surface-600">Searching…</p>
            {:else if mode === 'name_search_results' && nameSearchResults.length > 0}
              <ul class="space-y-2" data-testid="binder-name-search-results">
                {#each nameSearchResults as client (client.id)}
                  <li>
                    <button
                      type="button"
                      class="btn preset-tonal w-full justify-start text-left text-sm touch-target-h"
                      data-testid="binder-name-search-result-{client.id}"
                      onclick={() => selectClientFromNameSearch(client)}
                    >
                      {client.name}
                      {#if client.birth_year != null}
                        <span class="text-surface-600"> ({client.birth_year})</span>
                      {/if}
                    </button>
                  </li>
                {/each}
              </ul>
              {#if nameSearchMeta && nameSearchMeta.last_page > 1}
                <div class="flex items-center gap-2 flex-wrap">
                  <button
                    type="button"
                    class="btn preset-tonal text-xs touch-target-h"
                    disabled={nameSearchMeta.current_page <= 1}
                    onclick={() => runNameSearch(nameSearchMeta!.current_page - 1)}
                  >
                    Previous
                  </button>
                  <span class="text-xs text-surface-600">
                    Page {nameSearchMeta.current_page} of {nameSearchMeta.last_page}
                  </span>
                  <button
                    type="button"
                    class="btn preset-tonal text-xs touch-target-h"
                    disabled={nameSearchMeta.current_page >= nameSearchMeta.last_page}
                    onclick={() => runNameSearch(nameSearchMeta!.current_page + 1)}
                  >
                    Next
                  </button>
                </div>
              {/if}
              <button
                type="button"
                class="btn preset-tonal text-xs touch-target-h"
                onclick={() => (mode = 'idle')}
              >
                Back
              </button>
            {:else if mode === 'name_search_no_results'}
              <p class="text-xs text-surface-700">
                No matching clients found. You can create a new client.
              </p>
              <button
                type="button"
                class="btn preset-tonal text-xs touch-target-h"
                data-testid="binder-create-from-no-results-button"
                onclick={() => {
                  if (idType === 'Auto' && id_types?.length) idType = id_types[0];
                  mode = 'create_client_form';
                }}
              >
                Create new client
              </button>
              <button
                type="button"
                class="btn preset-tonal text-xs touch-target-h"
                onclick={() => (mode = 'idle')}
              >
                Back
              </button>
            {/if}
          </div>
        {:else}
          <!-- Idle / default: ID entry with HID scanner, camera scanner, lookup, and name search -->
          <div class="space-y-3">
            {#if allowHid}
              <input
                type="text"
                autocomplete="off"
                inputmode={isMobileTouch() ? 'none' : 'text'}
                aria-label="Barcode scanner input for ID lookup"
                class="sr-only"
                bind:value={binderHidValue}
                bind:this={binderHidInputEl}
                onkeydown={onBinderHidKeydown}
              />
            {/if}
            <p class="text-sm font-medium text-surface-950">Find or create client</p>
            <div class="space-y-2 pt-1">
              {#if allowCamera && !showManualIdEntry}
                <button
                  type="button"
                  class="btn preset-filled-primary-500 w-full text-sm touch-target-h flex items-center justify-center gap-2"
                  data-testid="binder-scan-id-camera-button"
                  onclick={() => {
                    showIdScanner = true;
                    idScanHandled = false;
                  }}
                  disabled={isBusy}
                >
                  <Camera class="w-4 h-4 shrink-0" />
                  Scan ID to capture number
                </button>
              {/if}
              {#if showManualIdEntry}
                <div
                  class="rounded-container border border-surface-200 bg-surface-50 p-3 space-y-3"
                  data-testid="binder-manual-id-entry-group"
                >
                  <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-medium text-surface-700">Enter ID manually</span>
                    {#if allowCamera}
                      <button
                        type="button"
                        class="btn btn-sm preset-tonal"
                        onclick={() => (showManualIdEntry = false)}
                      >
                        Use scanner
                      </button>
                    {/if}
                  </div>
                  <div class="space-y-1">
                    <span class="text-sm text-surface-700">ID number</span>
                    <IdNumberInput
                      bind:value={idNumber}
                      placeholder="Scan or type ID to look up"
                      disabled={isBusy}
                      showMaskToggle={true}
                      showScanButton={false}
                      scanButtonFullWidth={true}
                      inputClass="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                      testId="binder-id-number-input"
                      onKeydown={(e) => {
                        if (e.key === 'Enter') {
                          e.preventDefault();
                          submitIdLookup();
                        }
                      }}
                    />
                  </div>
                  <div class="flex flex-wrap gap-2 items-end">
                    <label class="flex flex-col gap-1 text-xs min-w-0 flex-1">
                      <span class="text-surface-700">ID type</span>
                      {#if idTypeOptions.length}
                        <select
                          class="select select-theme input rounded-container border border-surface-200 px-3 py-2 text-sm"
                          data-testid="binder-id-type-input"
                          bind:value={idType}
                          disabled={isBusy}
                          aria-label="ID type"
                        >
                          {#each idTypeOptions as type (type)}
                            <option value={type}>{type}</option>
                          {/each}
                        </select>
                      {:else}
                        <input
                          type="text"
                          class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                          data-testid="binder-id-type-input"
                          bind:value={idType}
                          disabled={isBusy}
                        />
                      {/if}
                    </label>
                    <button
                      type="button"
                      class="btn preset-filled-primary-500 touch-target-h"
                      data-testid="binder-lookup-button"
                      onclick={submitIdLookup}
                      disabled={isBusy}
                    >
                      {isBusy && mode === 'lookup_in_progress' ? 'Looking up…' : 'Lookup by ID'}
                    </button>
                  </div>
                </div>
              {:else}
                <button
                  type="button"
                  class="btn preset-tonal w-full touch-target-h"
                  data-testid="binder-enter-manually-button"
                  onclick={() => (showManualIdEntry = true)}
                  disabled={isBusy}
                >
                  Enter ID manually
                </button>
              {/if}
            </div>
          </div>
          <Modal
            open={showIdScanner}
            title="Scan ID"
            onClose={() => {
              showIdScanner = false;
              idScanHandled = true;
            }}
            wide={true}
          >
            {#snippet children()}
              <div class="flex flex-col gap-3">
                {#if allowHid}
                  <!-- First focusable so Modal focus trap focuses it; same value/handler as global input. -->
                  <input
                    type="text"
                    autocomplete="off"
                    inputmode={isMobileTouch() ? 'none' : 'text'}
                    aria-label="Barcode scanner input"
                    class="sr-only"
                    bind:value={binderHidValue}
                    bind:this={binderHidModalInputEl}
                    onkeydown={(e) => {
                      if (e.key !== 'Enter') return;
                      e.preventDefault();
                      handleModalHidScan();
                    }}
                  />
                {/if}
                <QrScanner
                  active={showIdScanner}
                  cameraOnly={true}
                  onScan={(decodedText: string) => {
                    if (idScanHandled) return;
                    idScanHandled = true;
                    const raw = decodedText.trim();
                    if (raw) {
                      showManualIdEntry = true;
                      idNumber = raw.slice(0, 255);
                      showIdScanner = false;
                    }
                  }}
                />
                {#if allowHid}
                  <p
                    class="text-sm text-surface-600 rounded-container border border-surface-200 bg-surface-50 px-3 py-2"
                    aria-live="polite"
                  >
                    HID scanner turned on, waiting for scan.
                  </p>
                {/if}
                <button
                  type="button"
                  class="btn preset-tonal"
                  onclick={() => {
                    showIdScanner = false;
                    idScanHandled = true;
                  }}
                >
                  Cancel
                </button>
              </div>
            {/snippet}
          </Modal>
        {/if}
      {/if}
    </div>
  {/if}
{/if}

