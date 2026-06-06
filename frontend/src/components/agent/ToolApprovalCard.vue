<script setup lang="ts">
/**
 * ToolApprovalCard — one per pending tool call. Owns its own per-tool state
 * (arguments JSON, reject-input toggle, in-flight flags) so TaskChatPage no
 * longer needs the keyed-by-id reactive maps it previously kept.
 *
 * Emits approve / reject events with the data the parent needs to call the
 * store. The parent owns the HTTP path and toasts.
 */
import { ref, computed, useId, watch } from 'vue'
import ToolArgumentsEditor from '@/components/agent/ToolArgumentsEditor.vue'
import {
  tryParseArgsObject,
  normalizeProposedArgs,
  prettyPrintArgs,
  REJECT_ONE_DEFAULT_REASON,
} from '@/composables/useToolApproval'
import type { ToolCall } from '@/types/task'

const props = defineProps<{
  toolCall: ToolCall
  approving?: boolean
  rejecting?: boolean
}>()

const emit = defineEmits<{
  approve: [payload: { providerCallId: string; arguments: Record<string, unknown> }]
  reject: [payload: { providerCallId: string; reason: string }]
  /**
   * Emitted whenever the in-card argument editor changes so the parent
   * (ToolApprovalBar) can keep a snapshot for bulk approve-all. Payload
   * mirrors the `approve` event shape but is fired on edit rather than
   * on click.
   */
  'update:arguments': [payload: { providerCallId: string; arguments: Record<string, unknown> }]
}>()

const argsJson = ref('')
const showRejectInput = ref(false)
const rejectReason = ref('')
const rejectOneReasonId = useId()

const parsedProposedArgs = computed<Record<string, unknown>>(() => {
  return normalizeProposedArgs(props.toolCall.proposed_arguments)
})

function emitCurrentArgs(): void {
  const parsed = tryParseArgsObject(argsJson.value)
  if (parsed === null) return
  emit('update:arguments', { providerCallId: props.toolCall.provider_call_id, arguments: parsed })
}

// Seed the editable JSON when the underlying proposed arguments change
// (e.g. a fresh tool call arrives). Also broadcast the initial value so the
// parent's "approve all" snapshot starts in sync with what the card shows.
watch(
  () => props.toolCall.id,
  () => {
    argsJson.value = prettyPrintArgs(parsedProposedArgs.value)
    showRejectInput.value = false
    rejectReason.value = ''
    emitCurrentArgs()
  },
  { immediate: true },
)

function onArgumentsUpdated(json: string): void {
  argsJson.value = json
  emitCurrentArgs()
}

function onApproveClick(): void {
  const parsed = tryParseArgsObject(argsJson.value)
  if (parsed === null) {
    // Parent surfaces the toast; we just refuse to emit an invalid payload.
    return
  }
  emit('approve', { providerCallId: props.toolCall.provider_call_id, arguments: parsed })
}

function onRejectClick(): void {
  emit('reject', {
    providerCallId: props.toolCall.provider_call_id,
    reason: rejectReason.value || REJECT_ONE_DEFAULT_REASON,
  })
}
</script>

<template>
  <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-white dark:bg-zinc-900 p-4 flex flex-col gap-3">
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0">
        <div class="flex items-center gap-2">
          <p class="text-sm font-semibold font-mono text-amber-900 dark:text-amber-100">{{ toolCall.tool_name }}</p>
          <span
            v-if="toolCall.operation && toolCall.operation !== 'default'"
            class="inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900/40 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300"
          >
            {{ toolCall.operation }}
          </span>
        </div>
        <p
          v-if="toolCall.operation_description"
          class="text-xs text-muted-foreground mt-0.5"
        >
          {{ toolCall.operation_description }}
        </p>
        <p
          v-else-if="toolCall.human_description"
          class="text-xs text-muted-foreground mt-0.5"
        >
          {{ toolCall.human_description }}
        </p>
      </div>
    </div>

    <ToolArgumentsEditor
      :arguments="toolCall.proposed_arguments"
      :tool-name="toolCall.tool_name"
      :operation="toolCall.operation"
      :parameter-schema="toolCall.parameter_schema ?? null"
      @update:arguments="onArgumentsUpdated"
    />

    <div v-if="showRejectInput" class="flex flex-col gap-1">
      <label :for="rejectOneReasonId" class="text-xs font-medium text-muted-foreground">Reason for rejecting "{{ toolCall.tool_name }}"</label>
      <input
        :id="rejectOneReasonId"
        v-model="rejectReason"
        type="text"
        :placeholder="`Explain why you're rejecting ${toolCall.tool_name}…`"
        class="w-full rounded-lg border border-border bg-white dark:bg-zinc-900 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
      />
    </div>

    <div class="flex gap-2">
      <button
        @click="onApproveClick"
        :disabled="approving"
        class="inline-flex h-8 flex-1 items-center justify-center rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium shadow transition-colors disabled:pointer-events-none disabled:opacity-50"
      >
        {{ approving ? 'Approving…' : '✓ Approve' }}
      </button>
      <button
        v-if="!showRejectInput"
        @click="showRejectInput = true"
        class="inline-flex h-8 items-center justify-center rounded-lg border border-border bg-white dark:bg-zinc-900 px-3 text-xs font-medium text-muted-foreground hover:text-foreground transition-colors"
      >
        ✗ Reject
      </button>
      <template v-else>
        <button
          @click="onRejectClick"
          :disabled="rejecting"
          class="inline-flex h-8 items-center justify-center rounded-lg border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-950/30 px-3 text-xs font-medium text-red-700 dark:text-red-300 hover:bg-red-100 transition-colors disabled:pointer-events-none disabled:opacity-50"
        >
          {{ rejecting ? 'Rejecting…' : 'Confirm Reject' }}
        </button>
        <button
          @click="showRejectInput = false; rejectReason = ''"
          class="inline-flex h-8 items-center justify-center rounded-lg border border-border px-2 text-xs text-muted-foreground hover:text-foreground transition-colors"
        >
          Cancel
        </button>
      </template>
    </div>
  </div>
</template>
