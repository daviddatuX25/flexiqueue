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
   *     'lookup_match_existing' | 'lookup_not_found'
   * - 'lookup_match_existing' →
   *     'binding_ready' | 'idle' | 'name_search_form' | 'lookup_in_progress'
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
  import { toaster } from '../lib/toaster.js';

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

  const { bindingMode = 'disabled', onBindingChange }: {
    bindingMode?: BindingMode;
    onBindingChange?: (payload: BinderChangeEvent) => void;
  } = $props();

  // Coarse mode + flags
  let mode = $state<BinderMode>('idle');
  let isBusy = $state(false);
  let errorMessage = $state('');

  // ID entry (can originate from scan or manual entry)
  let idType = $state<string>('PhilHealth');
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

  let selectedClient = $state<{ id: number; name: string; birth_year: number | null } | null>(null);
  let selectedIdDocument = $state<{ id: number; id_type: string; id_last4: string } | null>(null);

  let clientBinding = $state<ClientBindingPayload | null>(null);
  let binderStatus = $state<BinderStatus>('idle');

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
    idType = 'PhilHealth';
    idNumber = '';
    nameSearch = '';
    birthYearSearch = '';
    lookupResult = null;
    nameSearchResults = [];
    selectedClient = null;
    selectedIdDocument = null;
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

  // ID lookup
  async function submitIdLookup() {
    const trimmedId = idNumber.trim();
    if (!trimmedId) {
      errorMessage = 'Enter an ID number first.';
      return;
    }
    errorMessage = '';
    isBusy = true;
    mode = 'lookup_in_progress';

    const { ok, status, data, message, errors } = await api('POST', '/api/clients/lookup-by-id', {
      id_type: idType,
      id_number: trimmedId
    });
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

  // Name search – placeholder for now; wired for future endpoint.
  async function submitNameSearch() {
    const name = nameSearch.trim();
    if (!name) {
      errorMessage = 'Enter a name to search.';
      return;
    }
    errorMessage = '';
    isBusy = true;
    mode = 'name_search_in_progress';

    // Name search endpoint not yet implemented — returns no results
    // until a future bead wires the backend GET /api/clients/search.
    isBusy = false;
    nameSearchResults = [];
    mode = 'name_search_no_results';
  }

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
      body.id_document = {
        id_type: idType,
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

    const { ok, status, data, message, errors } = await api(
      'POST',
      `/api/clients/${encodeURIComponent(String(selectedClient.id))}/id-documents`,
      {
        id_type: idType,
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
              : 'Optional: bind to a client for better follow-up, or skip for this session.'}
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

      {#if mode === 'binding_confirmed' && clientBinding && selectedClient}
        <div
          class="rounded-container border border-success-200 bg-success-50 px-3 py-3 space-y-1"
          data-testid="binder-status-confirmed"
        >
          <p class="text-sm font-medium text-success-900">Binding confirmed</p>
          <p class="text-xs text-success-900/80">
            {selectedClient.name} ({selectedClient.birth_year ?? 'Year n/a'})
            {#if selectedIdDocument}
              · {selectedIdDocument.id_type} ending in {selectedIdDocument.id_last4}
            {/if}
          </p>
          <button
            type="button"
            class="btn preset-tonal text-xs mt-2 touch-target-h"
            onclick={resetBinderToIdle}
          >
            Change binding
          </button>
        </div>
      {:else if mode === 'binding_ready' && clientBinding && selectedClient}
        <div class="space-y-3" data-testid="binder-status-ready">
          <div class="rounded-container border border-surface-200 bg-surface-100 px-3 py-3 space-y-1">
            <p class="text-sm font-medium text-surface-950">Ready to bind</p>
            <p class="text-xs text-surface-800">
              {selectedClient.name} ({selectedClient.birth_year ?? 'Year n/a'})
              {#if selectedIdDocument}
                · {selectedIdDocument.id_type} ending in {selectedIdDocument.id_last4}
              {/if}
            </p>
          </div>
          <div class="flex gap-2">
            <button
              type="button"
              class="btn preset-tonal flex-1 touch-target-h"
              onclick={resetBinderToIdle}
              disabled={isBusy}
            >
              Start over
            </button>
            <button
              type="button"
              class="btn preset-filled-primary-500 flex-1 touch-target-h"
              data-testid="binder-confirm-binding-button"
              onclick={confirmBinding}
              disabled={isBusy}
            >
              Confirm binding
            </button>
          </div>
        </div>
      {:else}
        <!-- Main state machine body -->
        {#if mode === 'lookup_match_existing' && lookupResult}
          <div class="space-y-3">
            <p class="text-sm font-medium text-surface-950">Existing client found</p>
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
            <div class="flex flex-wrap gap-2">
              <button
                type="button"
                class="btn preset-filled-primary-500 touch-target-h flex-1"
                onclick={selectExistingClientFromLookup}
                disabled={isBusy}
              >
                Bind this client
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
                onclick={() => (mode = 'create_client_form')}
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
                <input
                  type="text"
                  class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  data-testid="binder-id-type-input"
                  bind:value={idType}
                  disabled={isBusy}
                />
              </label>
              <label class="flex flex-col gap-1 text-xs">
                <span class="text-surface-700">ID number (optional)</span>
                <input
                  type="text"
                  class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  data-testid="binder-id-number-input"
                  bind:value={idNumber}
                  placeholder="ID to bind (if available)"
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
              <input
                type="text"
                class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                data-testid="binder-attach-id-type-input"
                bind:value={idType}
                disabled={isBusy}
              />
            </label>
            <label class="flex flex-col gap-1 text-xs">
              <span class="text-surface-700">ID number</span>
              <input
                type="text"
                class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                data-testid="binder-attach-id-number-input"
                bind:value={idNumber}
                disabled={isBusy}
              />
            </label>
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
        {:else if mode === 'name_search_form' || mode === 'name_search_no_results'}
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
                  placeholder="Full name"
                  disabled={isBusy}
                />
              </label>
              <label class="flex flex-col gap-1 text-xs">
                <span class="text-surface-700">Birth year (optional)</span>
                <input
                  type="number"
                  class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  data-testid="binder-name-search-birth-year-input"
                  bind:value={birthYearSearch}
                  placeholder="e.g. 1985"
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
                data-testid="binder-name-search-submit-button"
                onclick={submitNameSearch}
                disabled={isBusy}
              >
                {isBusy ? 'Searching…' : 'Search'}
              </button>
            </div>
            {#if mode === 'name_search_no_results'}
              <p class="text-xs text-surface-700">
                No matching clients found. You can create a new client.
              </p>
              <button
                type="button"
                class="btn preset-tonal text-xs touch-target-h"
                data-testid="binder-create-from-no-results-button"
                onclick={() => (mode = 'create_client_form')}
              >
                Create new client
              </button>
            {/if}
          </div>
        {:else}
          <!-- Idle / default: ID entry with path to lookup or name search -->
          <div class="space-y-3">
            <p class="text-sm font-medium text-surface-950">Find or create client</p>
            <div class="space-y-2">
              <label class="flex flex-col gap-1 text-xs">
                <span class="text-surface-700">ID type</span>
                <input
                  type="text"
                  class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  data-testid="binder-id-type-input"
                  bind:value={idType}
                  disabled={isBusy}
                />
              </label>
              <label class="flex flex-col gap-1 text-xs">
                <span class="text-surface-700">ID number</span>
                <input
                  type="text"
                  class="input rounded-container border border-surface-200 px-3 py-2 text-sm"
                  data-testid="binder-id-number-input"
                  bind:value={idNumber}
                  placeholder="Scan or type ID to look up"
                  disabled={isBusy}
                  onkeydown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault();
                      submitIdLookup();
                    }
                  }}
                />
              </label>
            </div>
            <div class="flex flex-wrap gap-2">
              <button
                type="button"
                class="btn preset-filled-primary-500 flex-1 touch-target-h"
                data-testid="binder-lookup-button"
                onclick={submitIdLookup}
                disabled={isBusy}
              >
                {isBusy && mode === 'lookup_in_progress' ? 'Looking up…' : 'Lookup by ID'}
              </button>
              <button
                type="button"
                class="btn preset-tonal flex-1 touch-target-h"
                onclick={() => (mode = 'name_search_form')}
                disabled={isBusy}
              >
                Search by name
              </button>
            </div>
          </div>
        {/if}
      {/if}
    </div>
  {/if}
{/if}

