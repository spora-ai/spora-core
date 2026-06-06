/**
 * useTaskChatFollowup — follow-up prompt state + submit for TaskChatPage.
 *
 * Shown when the task is COMPLETED or FAILED and the agent allows continuation.
 * Submits via `taskStore.continueTask` and restarts detail polling.
 */
import { ref, computed } from 'vue'
import { useTaskStore } from '@/stores/tasks'
import { useAgentStore } from '@/stores/agent'
import { ApiError } from '@/api/client'

export function useTaskChatFollowup() {
  const taskStore = useTaskStore()
  const agentStore = useAgentStore()

  const followupPrompt = ref('')
  const submittingFollowup = ref(false)
  const followupError = ref<string | null>(null)

  const task = computed(() => taskStore.activeTask)

  const showFollowupBar = computed(() => {
    if (!task.value) return false
    if (task.value.status !== 'COMPLETED' && task.value.status !== 'FAILED') return false
    const agent = agentStore.currentAgent
    if (!agent) return false
    return agent.allow_continuation !== false
  })

  async function submitFollowup(): Promise<void> {
    const text = followupPrompt.value.trim()
    if (!text || !task.value) return
    followupError.value = null
    submittingFollowup.value = true
    try {
      await taskStore.continueTask(task.value.id, text)
      await taskStore.fetchTaskDetail(task.value.id)
      if (!taskStore.isTerminal) {
        taskStore.startDetailPolling(task.value.id)
      }
      followupPrompt.value = ''
    } catch (e) {
      followupError.value = e instanceof ApiError ? e.message : 'Failed to submit follow-up.'
    } finally {
      submittingFollowup.value = false
    }
  }

  return {
    followupPrompt,
    submittingFollowup,
    followupError,
    showFollowupBar,
    submitFollowup,
  }
}
