/**
 * useTaskChatApprovals — per-tool in-flight flags + bulk approve/reject
 * handlers for the ToolApprovalBar in TaskChatPage.
 *
 * The bar owns the per-tool argument editing; this composable tracks only
 * the HTTP-in-flight bits (so the bar can mark the correct card as
 * "Approving…") and translates bar events into store calls.
 */
import { ref } from 'vue'
import { useTaskStore } from '@/stores/tasks'
import { ApiError } from '@/api/client'
import { useToast } from '@/composables/useToast'

export function useTaskChatApprovals(taskId: { value: number }, onAfterMutation: () => void) {
  const taskStore = useTaskStore()
  const toast = useToast()

  const approveError = ref<string | null>(null)
  const approvingAll = ref(false)
  const rejecting = ref(false)
  const perToolApproving = ref<Record<number, boolean>>({})
  const perToolRejecting = ref<Record<number, boolean>>({})

  async function onApproveAll(payload: {
    approvals: Array<{ providerCallId: string; arguments: Record<string, unknown> }>
  }): Promise<void> {
    approveError.value = null
    approvingAll.value = true
    try {
      const approvals = payload.approvals.map((a) => ({
        provider_call_id: a.providerCallId,
        arguments: a.arguments,
      }))
      await taskStore.approveTask(taskId.value, approvals)
      toast.success('All tools approved.')
      taskStore.startDetailPolling(taskId.value)
      onAfterMutation()
    } catch (e) {
      const msg = e instanceof Error ? e.message : 'Approval failed.'
      toast.error(msg)
      approveError.value = msg
    } finally {
      approvingAll.value = false
    }
  }

  async function onRejectAll(payload: { reason: string }): Promise<void> {
    rejecting.value = true
    approveError.value = null
    try {
      await taskStore.rejectTask(taskId.value, payload.reason)
      toast.success('All tools rejected.')
      taskStore.startDetailPolling(taskId.value)
      onAfterMutation()
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Rejection failed.'
      toast.error(msg)
      approveError.value = msg
    } finally {
      rejecting.value = false
    }
  }

  async function onApproveOne(payload: {
    providerCallId: string
    arguments: Record<string, unknown>
  }): Promise<void> {
    const pending = taskStore.pendingToolCalls
    const tc = pending.find((t) => t.provider_call_id === payload.providerCallId)
    const id = tc?.id
    if (id !== undefined) perToolApproving.value[id] = true
    try {
      await taskStore.approveTask(taskId.value, [
        { provider_call_id: payload.providerCallId, arguments: payload.arguments },
      ])
      toast.success(`Tool "${tc?.tool_name ?? ''}" approved.`)
      taskStore.startDetailPolling(taskId.value)
      onAfterMutation()
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : `Failed to approve tool "${tc?.tool_name ?? ''}".`)
    } finally {
      if (id !== undefined) perToolApproving.value[id] = false
    }
  }

  async function onRejectOne(payload: { providerCallId: string; reason: string }): Promise<void> {
    const pending = taskStore.pendingToolCalls
    const tc = pending.find((t) => t.provider_call_id === payload.providerCallId)
    const id = tc?.id
    if (id !== undefined) perToolRejecting.value[id] = true
    try {
      await taskStore.rejectTask(taskId.value, payload.reason)
      toast.success(`Tool "${tc?.tool_name ?? ''}" rejected.`)
      taskStore.startDetailPolling(taskId.value)
      onAfterMutation()
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : `Failed to reject tool "${tc?.tool_name ?? ''}".`)
    } finally {
      if (id !== undefined) perToolRejecting.value[id] = false
    }
  }

  return {
    approveError,
    approvingAll,
    rejecting,
    perToolApproving,
    perToolRejecting,
    onApproveAll,
    onRejectAll,
    onApproveOne,
    onRejectOne,
  }
}
