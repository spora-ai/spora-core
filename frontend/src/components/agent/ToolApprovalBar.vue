<script setup lang="ts">
/**
 * ToolApprovalBar — sticky bar shown above the chat input when a task is
 * paused waiting for human approval. Owns the bulk approve-all / reject-all
 * controls and the reject-all reason input; delegates per-tool render and
 * state to ToolApprovalCard.
 *
 * The parent (TaskChatPage) supplies the pending list and reacts to events
 * by calling the task store. This component is purely presentational.
 */
import { ref, watch } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import ToolApprovalCard from '@/components/agent/ToolApprovalCard.vue'
import {
  buildBulkApprovals,
  pruneEditedArgs,
  inFlightFlag,
  REJECT_ALL_DEFAULT_REASON,
} from '@/composables/useToolApproval'
import type { ToolCall } from '@/types/task'

const props = defineProps<{
  pending: ToolCall[]
  approveError?: string | null
  approvingAll?: boolean
  rejecting?: boolean
  perToolApproving?: Record<number, boolean>
  perToolRejecting?: Record<number, boolean>
}>()

const emit = defineEmits<{
  'approve-all': [payload: { approvals: Array<{ providerCallId: string; arguments: Record<string, unknown> }> }]
  'reject-all': [payload: { reason: string }]
  'approve-one': [payload: { providerCallId: string; arguments: Record<string, unknown> }]
  'reject-one': [payload: { providerCallId: string; reason: string }]
}>()

const showRejectInput = ref(false)
const rejectReason = ref('')

// Snapshot of the most recent arguments each card emitted via update:arguments.
// Keyed by provider_call_id so it survives reordering and prunes naturally
// when a call leaves the pending list.
const editedArgs = ref<Record<string, Record<string, unknown>>>({})

watch(
  () => props.pending.map(tc => tc.provider_call_id),
  (ids) => {
    editedArgs.value = pruneEditedArgs(editedArgs.value, ids)
  },
)

function onCardArgumentsUpdated(payload: { providerCallId: string; arguments: Record<string, unknown> }): void {
  editedArgs.value[payload.providerCallId] = payload.arguments
}

function onApproveAll(): void {
  // Fall back to the proposed_arguments only when no card has emitted an
  // update yet (e.g. user clicked Approve All before any edit). This keeps
  // edited values intact even when only some cards were touched.
  const approvals = buildBulkApprovals(props.pending, editedArgs.value)
  emit('approve-all', { approvals })
}

function onRejectAllConfirm(): void {
  emit('reject-all', { reason: rejectReason.value || REJECT_ALL_DEFAULT_REASON })
  rejectReason.value = ''
  showRejectInput.value = false
}

function onRejectAllCancel(): void {
  showRejectInput.value = false
  rejectReason.value = ''
}

function isApproving(id: number): boolean {
  return inFlightFlag(props.perToolApproving, id)
}

function isRejecting(id: number): boolean {
  return inFlightFlag(props.perToolRejecting, id)
}
</script>

<template>
  <div class="border-t border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 shrink-0 sticky top-0 z-10">
    <div class="max-w-2xl w-full mx-auto px-4 py-4 flex flex-col gap-4">

      <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-2 min-w-0">
          <Icon name="warning" class="h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0" />
          <span class="text-sm font-semibold text-amber-800 dark:text-amber-200 truncate">
            {{ pending.length === 1 ? 'Tool approval required' : `${pending.length} tool approvals required` }}
          </span>
        </div>

        <div v-if="pending.length > 1" class="flex gap-2 shrink-0">
          <button
            @click="onApproveAll"
            :disabled="approvingAll"
            class="inline-flex h-8 items-center justify-center rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium shadow transition-colors disabled:pointer-events-none disabled:opacity-50"
          >
            {{ approvingAll ? 'Approving…' : '✓ Approve All' }}
          </button>
          <button
            v-if="!showRejectInput"
            @click="showRejectInput = true"
            class="inline-flex h-8 items-center justify-center rounded-lg border border-border bg-white dark:bg-zinc-900 px-3 text-xs font-medium text-muted-foreground hover:text-foreground transition-colors"
          >
            ✗ Reject All
          </button>
          <template v-else>
            <button
              @click="onRejectAllConfirm"
              :disabled="rejecting"
              class="inline-flex h-8 items-center justify-center rounded-lg border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-950/30 px-3 text-xs font-medium text-red-700 dark:text-red-300 hover:bg-red-100 transition-colors disabled:pointer-events-none disabled:opacity-50"
            >
              {{ rejecting ? 'Rejecting…' : 'Confirm Reject All' }}
            </button>
            <button
              @click="onRejectAllCancel"
              class="inline-flex h-8 items-center justify-center rounded-lg border border-border px-2 text-xs text-muted-foreground hover:text-foreground transition-colors"
            >
              Cancel
            </button>
          </template>
        </div>
      </div>

      <div v-if="showRejectInput && pending.length > 1" class="flex flex-col gap-1.5">
        <label class="text-xs font-medium text-muted-foreground">Reason for rejecting all tools</label>
        <input
          v-model="rejectReason"
          type="text"
          placeholder="Explain why you're rejecting all actions…"
          class="w-full rounded-lg border border-border bg-white dark:bg-zinc-900 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
        />
      </div>

      <p v-if="approveError" role="alert" class="text-xs text-destructive">{{ approveError }}</p>

      <ToolApprovalCard
        v-for="tc in pending"
        :key="tc.id"
        :tool-call="tc"
        :approving="isApproving(tc.id)"
        :rejecting="isRejecting(tc.id)"
        @approve="emit('approve-one', $event)"
        @reject="emit('reject-one', $event)"
        @update:arguments="onCardArgumentsUpdated"
      />
    </div>
  </div>
</template>
