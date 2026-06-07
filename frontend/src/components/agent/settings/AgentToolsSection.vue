<script setup lang="ts">
/**
 * AgentToolsSection — tool registry grouped by category with enable/disable,
 * per-tool configuration, and operation auto-approve toggles.
 *
 * Owns the local state (registry, status map, per-tool saving flags,
 * collapsed categories, modal flags) and the enable/disable + operation
 * override flows. The page provides the agent + agentId.
 */
import { ref, computed, onMounted } from 'vue'
import { useAgentStore } from '@/stores/agent'
import { useToolSettings, type ToolSchema, type ToolStatus } from '@/composables/useToolSettings'
import { categoryLabel, groupToolsByCategory, sortCategoryKeys } from '@/utils/toolCategories'
import { ApiError, api } from '@/api/client'
import AgentToolListItem from '@/components/agent/AgentToolListItem.vue'
import AgentToolConfigModal from '@/components/agent/AgentToolConfigModal.vue'
import EnableWarningModal from '@/components/agent/EnableWarningModal.vue'
import Icon from '@/components/ui/Icon.vue'

interface Agent {
  id: number
  tools: Array<{ tool_name: string }>
}

const props = defineProps<{
  agent: Agent
  agentId: number
}>()

const agentStore = useAgentStore()
const toolSettings = useToolSettings(props.agentId)

const toolRegistry = ref<ToolSchema[]>([])
const toolStatusMap = ref<Record<string, ToolStatus>>({})
const enabledToolNames = ref<Set<string>>(new Set())
const savingTool = ref<Record<string, boolean>>({})
const savingOperation = ref<Record<string, boolean>>({})
const operationStates = ref<Record<string, Record<string, { enabled: boolean; requiresApproval: boolean }>>>({})
const error = ref<string | null>(null)

const configuringTool = ref<string | null>(null)
const pendingEnableTool = ref<string | null>(null)
const collapsedCategories = ref<Record<string, boolean>>({})

const toolsByCategory = computed(() => groupToolsByCategory(toolRegistry.value))
const sortedCategories = computed(() => sortCategoryKeys(toolsByCategory.value))

function configuringToolSchema(): ToolSchema | null {
  return toolRegistry.value.find((t) => t.tool_name === configuringTool.value) ?? null
}

function toLabel(cat: string): string {
  return categoryLabel(cat)
}

function showEnableWarning(toolName: string): void {
  pendingEnableTool.value = toolName
}

onMounted(async () => {
  enabledToolNames.value = new Set(props.agent.tools.map((t) => t.tool_name))

  const [toolsResult, allStatuses] = await Promise.all([
    api.get<{ tools: ToolSchema[] }>('/tools'),
    toolSettings.getAllToolStatuses(),
  ])
  toolRegistry.value = toolsResult.tools
  toolStatusMap.value = allStatuses

  for (const tool of props.agent.tools) {
    const status = allStatuses[tool.tool_name]
    if (status) {
      if (status.is_enabled) enabledToolNames.value.add(tool.tool_name)
      else enabledToolNames.value.delete(tool.tool_name)
    }
  }
  await loadOperationOverrides()
})

async function loadOperationOverrides(): Promise<void> {
  operationStates.value = await agentStore.getAllOperationOverrides(props.agentId)
}

async function toggleTool(toolName: string): Promise<void> {
  savingTool.value[toolName] = true
  error.value = null
  try {
    if (enabledToolNames.value.has(toolName)) {
      await agentStore.disableTool(props.agentId, toolName)
      enabledToolNames.value.delete(toolName)
    } else {
      const status = toolStatusMap.value[toolName]
      if (status && !status.can_enable) {
        showEnableWarning(toolName)
        savingTool.value[toolName] = false
        return
      }
      await agentStore.enableTool(props.agentId, toolName)
      const newStatus = await toolSettings.getToolStatus(toolName)
      if (newStatus === null) {
        showEnableWarning(toolName)
        savingTool.value[toolName] = false
        return
      }
      if (!newStatus.can_enable) {
        toolStatusMap.value[toolName] = newStatus
        showEnableWarning(toolName)
        savingTool.value[toolName] = false
        return
      }
      enabledToolNames.value.add(toolName)
      toolStatusMap.value[toolName] = newStatus
      await loadOperationOverrides()
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to update tool.'
  } finally {
    savingTool.value[toolName] = false
  }
}

async function toggleOperationEnabled(toolName: string, operationName: string): Promise<void> {
  savingOperation.value[toolName] = true
  error.value = null
  const prev = operationStates.value[toolName]?.[operationName]
  try {
    const newEnabled = !prev?.enabled
    await agentStore.patchOperationOverride(props.agentId, toolName, operationName, { enabled: newEnabled })
    if (!operationStates.value[toolName]) {
      operationStates.value[toolName] = {}
    }
    operationStates.value[toolName][operationName] = {
      enabled: newEnabled,
      requiresApproval: prev?.requiresApproval ?? true,
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to update operation.'
    if (operationStates.value[toolName]?.[operationName]) {
      operationStates.value[toolName][operationName] = prev
    }
  } finally {
    savingOperation.value[toolName] = false
  }
}

async function toggleOperationAutoApprove(toolName: string, operationName: string): Promise<void> {
  savingOperation.value[toolName] = true
  error.value = null
  const prev = operationStates.value[toolName]?.[operationName]
  try {
    const currentRequiresApproval = prev?.requiresApproval ?? true
    const newRequiresApproval = !currentRequiresApproval
    await agentStore.patchOperationOverride(props.agentId, toolName, operationName, {
      default_requires_approval: newRequiresApproval,
    })
    if (!operationStates.value[toolName]) {
      operationStates.value[toolName] = {}
    }
    operationStates.value[toolName][operationName] = {
      enabled: prev?.enabled ?? true,
      requiresApproval: newRequiresApproval,
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to update operation auto-approve.'
    if (operationStates.value[toolName]?.[operationName]) {
      operationStates.value[toolName][operationName] = prev
    }
  } finally {
    savingOperation.value[toolName] = false
  }
}

async function onToolSaved(toolName: string): Promise<void> {
  const newStatus = await toolSettings.getToolStatus(toolName)
  if (newStatus !== null) {
    toolStatusMap.value[toolName] = newStatus
  }
}
</script>

<template>
  <section class="rounded-xl border border-border bg-card divide-y divide-border">
    <div class="px-5 py-4">
      <h2 class="text-base font-semibold">Tools</h2>
    </div>

    <template v-for="cat in sortedCategories" :key="cat">
      <div
        class="px-5 py-3 flex items-center justify-between bg-muted/30 cursor-pointer select-none"
        @click="collapsedCategories[cat] = !collapsedCategories[cat]"
      >
        <h3 class="text-sm font-medium">{{ toLabel(cat) }}</h3>
        <div class="flex items-center gap-2">
          <span class="text-xs text-muted-foreground">{{ toolsByCategory[cat].length }}</span>
          <Icon
            name="chevron-down"
            :class="`h-4 w-4 text-muted-foreground transition-transform ${collapsedCategories[cat] ? '-rotate-90' : ''}`"
          />
        </div>
      </div>
      <template v-if="!collapsedCategories[cat]">
        <AgentToolListItem
          v-for="tool in toolsByCategory[cat]"
          :key="tool.tool_name"
          :tool="tool"
          :enabled="enabledToolNames.has(tool.tool_name)"
          :saving="savingTool[tool.tool_name] ?? false"
          :missingRequired="toolStatusMap[tool.tool_name]?.missing_required ?? []"
          :operationStates="operationStates[tool.tool_name]"
          @toggle="toggleTool(tool.tool_name)"
          @openConfig="configuringTool = tool.tool_name"
          @toggleOperationEnabled="(op) => toggleOperationEnabled(tool.tool_name, op)"
          @toggleOperationAutoApprove="(op) => toggleOperationAutoApprove(tool.tool_name, op)"
        />
      </template>
    </template>

    <div v-if="toolRegistry.length === 0" class="px-5 py-4 text-sm text-muted-foreground">
      No tools registered.
    </div>
    <p v-if="error" role="alert" data-testid="tools-error" class="px-5 py-3 text-xs text-destructive">{{ error }}</p>

    <AgentToolConfigModal
      :toolName="configuringTool"
      :tool="configuringToolSchema()"
      :agentId="agentId"
      @saved="onToolSaved"
      @close="configuringTool = null"
    />

    <EnableWarningModal
      :toolName="pendingEnableTool"
      :missingRequired="pendingEnableTool ? (toolStatusMap[pendingEnableTool]?.missing_required ?? []) : []"
      @configure="() => { configuringTool = pendingEnableTool; pendingEnableTool = null }"
      @close="pendingEnableTool = null"
    />
  </section>
</template>
