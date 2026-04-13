import { describe, it, expect, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useTaskStore } from '../stores/tasks'
import type { TaskDetail } from '../types/task'

describe('useTaskStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  function makeMinimalTaskDetail(partial: Partial<TaskDetail> = {}): TaskDetail {
    return {
      id: 1,
      agent_id: 1,
      status: 'RUNNING',
      user_prompt: 'test',
      final_response: null,
      step_count: 0,
      max_steps: 10,
      created_at: '2026-01-01T00:00:00Z',
      updated_at: '2026-01-01T00:00:00Z',
      tool_calls: [],
      history: [],
      ...partial,
    }
  }

  describe('pendingToolCalls', () => {
    it('returns an empty array when activeTask is null', () => {
      const store = useTaskStore()
      expect(Array.isArray(store.pendingToolCalls)).toBe(true)
      expect(store.pendingToolCalls).toEqual([])
    })

    it('returns an empty array when activeTask has no tool_calls', () => {
      const store = useTaskStore()
      store.activeTask = makeMinimalTaskDetail({ tool_calls: undefined })
      expect(Array.isArray(store.pendingToolCalls)).toBe(true)
      expect(store.pendingToolCalls).toEqual([])
    })

    it('returns filtered PENDING tool calls', () => {
      const store = useTaskStore()
      store.activeTask = makeMinimalTaskDetail({
        tool_calls: [
          { id: 1, status: 'PENDING', tool_name: 'test', tool_type: 'input', proposed_arguments: null, approved_arguments: null, human_description: null, result_content: null, executed_at: null },
          { id: 2, status: 'EXECUTED', tool_name: 'test', tool_type: 'input', proposed_arguments: null, approved_arguments: null, human_description: null, result_content: null, executed_at: null },
        ],
      })
      expect(store.pendingToolCalls).toHaveLength(1)
      expect(store.pendingToolCalls[0].id).toBe(1)
    })

    it('can be iterated with for...of', () => {
      const store = useTaskStore()
      store.activeTask = makeMinimalTaskDetail({
        tool_calls: [
          { id: 1, status: 'PENDING', tool_name: 'test', tool_type: 'input', proposed_arguments: null, approved_arguments: null, human_description: null, result_content: null, executed_at: null },
        ],
      })
      const results: unknown[] = []
      for (const tc of store.pendingToolCalls) {
        results.push(tc)
      }
      expect(results).toHaveLength(1)
    })
  })
})
