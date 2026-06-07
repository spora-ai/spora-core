<script setup lang="ts">
/**
 * AgentSettingsPage — agent configuration. Route: /agents/:id/settings.
 *
 * A thin layout shell that fetches the agent, then delegates the four
 * sections (Identity, LLM, Tools, Danger Zone) to focused sub-components.
 */
import { computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAgentStore } from '@/stores/agent'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import { useLlmPreferencesStore } from '@/stores/llmPreferencesStore'
import AgentLayout from '@/components/layout/AgentLayout.vue'
import AgentIdentitySection from '@/components/agent/settings/AgentIdentitySection.vue'
import AgentLlmSection from '@/components/agent/settings/AgentLlmSection.vue'
import AgentToolsSection from '@/components/agent/settings/AgentToolsSection.vue'
import AgentDangerZone from '@/components/agent/settings/AgentDangerZone.vue'

const route = useRoute()
const router = useRouter()
const agentStore = useAgentStore()
const llmConfigsStore = useLlmConfigsStore()
const preferenceStore = useLlmPreferencesStore()

const agentId = computed(() => Number(route.params.id))

onMounted(async () => {
  await Promise.all([
    agentStore.fetchAgents(),
    agentStore.fetchAgent(agentId.value),
    llmConfigsStore.ensure(),
    preferenceStore.loadPreference(),
  ])
})

function onDeleted(): void {
  router.push({ name: 'dashboard' })
}
</script>

<template>
  <AgentLayout :agent-id="agentId">
    <div v-if="!agentStore.currentAgent" class="flex-1 flex items-center justify-center text-sm text-muted-foreground">
      Loading…
    </div>

    <main v-else class="flex-1 py-8 px-6 flex flex-col gap-8">
      <AgentIdentitySection :agent="agentStore.currentAgent" :agent-id="agentId" />
      <AgentLlmSection :agent="agentStore.currentAgent" :agent-id="agentId" />
      <AgentToolsSection :agent="agentStore.currentAgent" :agent-id="agentId" />
      <AgentDangerZone :agent="agentStore.currentAgent" :agent-id="agentId" @deleted="onDeleted" />
    </main>
  </AgentLayout>
</template>
