<script lang="ts">
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
  import { RotateCcw } from 'lucide-svelte';
  import { toaster } from '../lib/toaster.js';
  import { clientDisplayName } from '../lib/clientDisplayName.js';

  type BinderMode =
    | 'idle'
    | 'scanning'
    | 'lookup_in_progress'
    | 'lookup_match_existing'
    | 'lookup_not_found'
    | 'name_search_form'
    | 'name_search_in_progress'
    | 'name_search_results'
    | 'name_search_no_results'
    | 'create_client_form'
    | 'create_client_submitting'
    | 'create_client_error'
    | 'binding_ready'
    | 'binding_confirmed'
    | 'skipped';

  type BindingSource = 'phone_match' | 'new_client' | 'name_search' | 'manual';

  export type ClientBindingPayload = {
    client_id: number;
    source: BindingSource;
  };

  export type BinderStatus = 'idle' | 'bound' | 'skipped';

  export type BinderChangeEvent = {
    status: BinderStatus;
    client_binding: ClientBindingPayload | null;
    summary?: {
      client_name: string;
      birth_date: string | null;
      mobile_masked?: string | null;
    };
  };

  type ClientFromApi = {
    id: number;
    first_name: string;
    middle_name?: string | null;
    last_name: string;
    birth_date?: string | null;
    mobile_masked?: string | null;
  };

  /** Per IDENTITY-BINDING-FINAL-IMPLEMENTATION-PLAN: optional washed out. */
  export type BindingMode = 'disabled' | 'required';

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
    programId = null,
    allowHid = true,
    allowCamera = true,
    onBindingChange
  }: {
    bindingMode?: BindingMode;
    /** Program ID for site-scoped client search/create (required for search-by-phone to work). */
    programId?: number | null;
    allowHid?: boolean;
    allowCamera?: boolean;
    /** @deprecated no longer used (phone-based binding) */
    id_types?: string[];
    onBindingChange?: (payload: BinderChangeEvent) => void;
  } = $props();

  // Coarse mode + flags
  let mode = $state<BinderMode>('idle');
  let isBusy = $state(false);
  let errorMessage = $state('');

  // Phone entry for lookup
  let mobile = $state<string>('');

  // Name search
  let nameSearch = $state<string>('');
  let birthDateSearch = $state<string>('');

  // Results
  let lookupResult = $state<{
    client: ClientFromApi | null;
    mobile_masked?: string | null;
  } | null>(null);

  let nameSearchResults = $state<ClientFromApi[]>([]);
  let nameSearchMeta = $state<{ current_page: number; last_page: number; total: number; per_page: number } | null>(null);
  let nameSearchDebounceId = 0;
  const NAME_SEARCH_DEBOUNCE_MS = 250;
  const NAME_SEARCH_PER_PAGE = 3;

  let selectedClient = $state<ClientFromApi | null>(null);
  let selectedMobileMasked = $state<string | null>(null);

  let clientBinding = $state<ClientBindingPayload | null>(null);
  let binderStatus = $state<BinderStatus>('idle');

  const isPhoneLookupMode = $derived(
    mode === 'idle' ||
      mode === 'scanning' ||
      mode === 'lookup_in_progress' ||
      mode === 'lookup_match_existing' ||
      mode === 'lookup_not_found'
  );
  const isNameSearchMode = $derived(
    mode === 'name_search_form' ||
      mode === 'name_search_in_progress' ||
      mode === 'name_search_results' ||
      mode === 'name_search_no_results'
  );
  // Hide nav when showing result actions (avoid duplicate "Search by phone" / "Search by name")
  const showBinderNav = $derived(
    mode !== 'binding_confirmed' && mode !== 'lookup_not_found' && mode !== 'lookup_match_existing'
  );

  function emitChange() {
    onBindingChange?.({
      status: binderStatus,
      client_binding: clientBinding,
      summary: clientBinding && selectedClient
        ? {
            client_name: clientDisplayName(selectedClient),
            birth_date: selectedClient.birth_date ?? null,
            mobile_masked: selectedMobileMasked ?? selectedClient.mobile_masked
          }
        : undefined
    });
  }

  function resetBinderToIdle() {
    mode = 'idle';
    isBusy = false;
    errorMessage = '';
    mobile = '';
    nameSearch = '';
    birthDateSearch = '';
    lookupResult = null;
    nameSearchResults = [];
    nameSearchMeta = null;
    if (nameSearchDebounceId) {
      clearTimeout(nameSearchDebounceId);
      nameSearchDebounceId = 0;
    }
    selectedClient = null;
    selectedMobileMasked = null;
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
    emitChange();
  }

  async function submitPhoneSearch() {
    const trimmed = mobile.trim();
    if (!trimmed) {
      errorMessage = 'Enter a phone number first.';
      return;
    }
    errorMessage = '';
    isBusy = true;
    mode = 'lookup_in_progress';

    const body: { mobile: string; program_id?: number } = { mobile: trimmed };
    if (programId != null) body.program_id = programId;
    const url =
      programId != null
        ? `/api/clients/search-by-phone?program_id=${encodeURIComponent(programId)}`
        : '/api/clients/search-by-phone';
    const { ok, status, data, message, errors } = await api('POST', url, body);
    isBusy = false;

    if (!ok) {
      if (status === 422) {
        errorMessage =
          (errors && (errors.mobile?.[0] ?? errors.program_id?.[0])) ||
          (message ?? 'Validation failed.');
      } else {
        errorMessage = message ?? 'Search failed.';
      }
      mode = 'idle';
      return;
    }

    const matchStatus = (data as { match_status?: string }).match_status;
    if (matchStatus === 'existing') {
      const client = (data as { client?: ClientFromApi }).client!;
      const mobileMasked = client.mobile_masked ?? null;
      lookupResult = { client: { ...client, mobile_masked: mobileMasked }, mobile_masked: mobileMasked };
      selectedClient = lookupResult.client;
      selectedMobileMasked = mobileMasked;
      mode = 'lookup_match_existing';
    } else {
      lookupResult = { client: null };
      mode = 'lookup_not_found';
    }
  }

  // Name search: GET /api/clients/search, keydown-triggered with debounce. Per page = 3.
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
      const params = new URLSearchParams({
        name,
        per_page: String(NAME_SEARCH_PER_PAGE),
        page: String(page),
      });
      const bd = birthDateSearch.trim();
      if (bd) params.set('birth_date', bd);
      if (programId != null) {
        params.set('program_id', String(programId));
      }
      const { ok, data } = await api('GET', `/api/clients/search?${params.toString()}`);
      if (!ok || !data) {
        nameSearchResults = [];
        nameSearchMeta = null;
        mode = 'name_search_no_results';
        return;
      }
      const payload = data as { data?: ClientFromApi[]; meta?: { current_page: number; last_page: number; total: number; per_page: number } };
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

  function selectClientFromNameSearch(client: ClientFromApi) {
    selectedClient = client;
    selectedMobileMasked = client.mobile_masked ?? null;
    clientBinding = {
      client_id: client.id,
      source: 'name_search',
    };
    mode = 'binding_ready';
    binderStatus = 'binding_ready';
    emitChange();
  }

  function selectExistingClientFromLookup() {
    if (!lookupResult?.client) return;
    selectedClient = lookupResult.client;
    selectedMobileMasked = lookupResult.mobile_masked ?? null;
    clientBinding = {
      client_id: selectedClient.id,
      source: 'phone_match',
    };
    mode = 'binding_ready';
    binderStatus = 'binding_ready';
    emitChange();
  }

  async function submitCreateClient() {
    const name = nameSearch.trim() || (selectedClient ? clientDisplayName(selectedClient) : '');
    const birthDateVal = birthDateSearch.trim();
    if (!name) {
      errorMessage = 'Enter a name for the new client.';
      return;
    }
    if (!birthDateVal) {
      errorMessage = 'Enter a birth date (YYYY-MM-DD).';
      return;
    }
    const parts = name.split(/\s+/);
    const first_name = parts[0] ?? '';
    const last_name = parts.slice(1).join(' ') || first_name;

    errorMessage = '';
    isBusy = true;
    mode = 'create_client_submitting';

    const body: { first_name: string; last_name: string; birth_date: string; mobile?: string; program_id?: number } = {
      first_name,
      last_name,
      birth_date: birthDateVal
    };
    const trimmedMobile = mobile.trim();
    if (trimmedMobile) {
      body.mobile = trimmedMobile;
    }
    if (programId != null) {
      body.program_id = programId;
    }

    const { ok, status, data, message, errors } = await api('POST', '/api/clients', body);
    isBusy = false;

    if (!ok) {
      if (status === 409 && (data as { error_code?: string })?.error_code === 'mobile_duplicate') {
        errorMessage =
          (data as { hint?: string; message?: string })?.hint ||
          (data as { message?: string })?.message ||
          'A client with this phone already exists. Use search by phone instead.';
        mode = 'create_client_error';
        return;
      }
      if (status === 422 && errors) {
        errorMessage =
          errors.first_name?.[0] ||
          errors.last_name?.[0] ||
          errors.birth_date?.[0] ||
          errors.mobile?.[0] ||
          'Validation failed.';
        mode = 'create_client_error';
        return;
      }
      errorMessage = message || 'Could not create client.';
      mode = 'create_client_error';
      return;
    }

    const client = (data as { client?: ClientFromApi }).client!;
    selectedClient = client;
    selectedMobileMasked = client.mobile_masked ?? null;
    clientBinding = {
      client_id: client.id,
      source: trimmedMobile ? 'new_client' : 'manual',
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
							: 'Binding is required for this program.'}
          </p>
        </div>
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
              isPhoneLookupMode ? 'preset-filled-primary-500' : 'preset-tonal'
            }`}
            onclick={() => {
              mode = 'idle';
              errorMessage = '';
              nameSearch = '';
              birthDateSearch = '';
              nameSearchResults = [];
              nameSearchMeta = null;
            }}
            disabled={isBusy}
          >
            Search by phone
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

      {#if (mode === 'binding_ready' || mode === 'binding_confirmed') && clientBinding && selectedClient}
        <div
          class="flex items-center gap-3 rounded-container border border-surface-200 bg-surface-100 px-2 py-2"
          data-testid="binder-status-ready"
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
            <span class="font-medium text-surface-950">{clientDisplayName(selectedClient)}</span>
            {#if selectedMobileMasked}
              <span class="text-surface-600"> · {selectedMobileMasked}</span>
            {:else}
              <span class="text-surface-500"> · No phone stored</span>
            {/if}
          </p>
        </div>
      {:else}
        <!-- Main state machine body -->
        {#if mode === 'lookup_match_existing' && lookupResult}
          <div class="space-y-3" data-testid="binder-found-match-confirmation">
            <p class="text-sm font-semibold text-surface-950">Found match</p>
            <div class="rounded-container border border-surface-200 bg-surface-100 px-3 py-3 space-y-1">
              <p class="text-xs font-medium text-surface-900">
                {lookupResult.client ? clientDisplayName(lookupResult.client) : ''}
              </p>
              <p class="text-xs text-surface-700">
                Birth date: {lookupResult.client?.birth_date ?? 'n/a'}
              </p>
              {#if lookupResult.mobile_masked}
                <p class="text-xs text-surface-700">
                  Phone: {lookupResult.mobile_masked}
                </p>
              {/if}
            </div>
            <p class="text-xs text-surface-600">Is this the correct client?</p>
            <div class="flex flex-wrap gap-2">
              <button
                type="button"
                class="btn preset-filled-primary-500 touch-target-h flex-1"
                data-testid="binder-found-match-proceed"
                onclick={selectExistingClientFromLookup}
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
        {:else if mode === 'lookup_not_found'}
          <div class="space-y-3">
            <p class="text-sm font-medium text-surface-950">No client with this phone</p>
            <p class="text-xs text-surface-700">
              You can create a new client with this phone, search by name, or try a different number.
            </p>
            <div class="flex flex-wrap gap-2">
              <button
                type="button"
                class="btn preset-filled-primary-500 touch-target-h flex-1"
                onclick={() => { mode = 'create_client_form'; }}
              >
                Create new client with this phone
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
                Try different number
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
                <span class="text-surface-700">Birth date (required)</span>
                <input
                  type="date"
                  class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  data-testid="binder-create-birth-date-input"
                  bind:value={birthDateSearch}
                  disabled={isBusy}
                />
              </label>
              <label class="flex flex-col gap-1 text-xs">
                <span class="text-surface-700">Phone (optional)</span>
                <input
                  type="tel"
                  inputmode="numeric"
                  class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  data-testid="binder-create-mobile-input"
                  bind:value={mobile}
                  placeholder="e.g. 09171234567"
                  disabled={isBusy}
                />
              </label>
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
                <span class="text-surface-700">Birth date (optional)</span>
                <input
                  type="date"
                  class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  data-testid="binder-name-search-birth-date-input"
                  bind:value={birthDateSearch}
                  onkeydown={scheduleNameSearch}
                  oninput={scheduleNameSearch}
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
                      {clientDisplayName(client)}
                      {#if client.birth_date}
                        <span class="text-surface-600"> ({client.birth_date})</span>
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
                onclick={() => { mode = 'create_client_form'; }}
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
          <!-- Idle / default: phone entry only (no scan until printable ID and scan feature exist) -->
          <div class="space-y-3">
            <p class="text-sm font-medium text-surface-950">Find or create client</p>
            <div
              class="rounded-container border border-surface-200 bg-surface-50 p-3 space-y-3"
              data-testid="binder-manual-phone-entry-group"
            >
              <label class="flex flex-col gap-1 text-xs">
                <span class="text-surface-700">Phone number</span>
                <input
                  type="tel"
                  inputmode="numeric"
                  class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  data-testid="binder-phone-input"
                  bind:value={mobile}
                  placeholder="e.g. 09171234567"
                  disabled={isBusy}
                  onkeydown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault();
                      submitPhoneSearch();
                    }
                  }}
                />
              </label>
              <button
                type="button"
                class="btn preset-filled-primary-500 touch-target-h w-full"
                data-testid="binder-lookup-button"
                onclick={submitPhoneSearch}
                disabled={isBusy}
              >
                {isBusy && mode === 'lookup_in_progress' ? 'Searching…' : 'Search by phone'}
              </button>
            </div>
          </div>
        {/if}
      {/if}
    </div>
  {/if}
{/if}

