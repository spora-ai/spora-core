<script setup lang="ts">
/**
 * AgentSidebar — left sidebar showing agent list.
 * Used inside AgentLayout on lg+ (desktop) and toggled on mobile.
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAgentStore } from '@/stores/agent'
import CreateAgentModal from '@/components/agent/CreateAgentModal.vue'
import Icon from '@/components/ui/Icon.vue'

const props = defineProps<{
  agentId: number
  mobileOpen?: boolean
}>()

const emit = defineEmits<{
  close: () => void
}>()

const router = useRouter()
const agentStore = useAgentStore()

const showNewAgentModal = ref(false)
const activeAgentId = computed(() => props.agentId)

function navigateToAgent(id: number): void {
  router.push({ name: 'agent', params: { id } })
  closeSidebar()
}

// @ts-ignore TS is confused by emit() in arrow fn passed to another function
const emitCloseSidebar = () => emit('close')

const closeSidebar = emitCloseSidebar
</script>

<template>
  <!-- Mobile backdrop -->
  <Transition name="fade">
    <div
      v-if="mobileOpen"
      class="fixed inset-0 z-40 bg-black/50 lg:hidden"
      @click="closeSidebar()"
    />
  </Transition>

  <!-- Sidebar -->
  <aside
    class="flex flex-col border-r border-border bg-background shrink-0 overflow-y-auto"
    :class="[
      mobileOpen
        ? 'fixed inset-y-0 left-0 z-50 w-72 shadow-xl lg:hidden'
        : 'hidden lg:flex w-64'
    ]"
  >
    <!-- Sidebar header -->
    <div class="px-4 py-3 border-b border-border flex items-center justify-between bg-background">
      <span class="text-sm font-semibold text-foreground">Agents</span>
      <button
        @click="showNewAgentModal = true"
        class="flex items-center justify-center h-7 w-7 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
        title="New Agent"
      >
        <Icon name="plus" />
      </button>
      <button
        v-if="mobileOpen"
        @click="closeSidebar()"
        class="flex items-center justify-center h-7 w-7 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted transition-colors lg:hidden"
        title="Close"
      >
        <Icon name="x" />
      </button>
    </div>

    <!-- Agent list -->
    <ul class="flex-1 py-2">
      <li
        v-for="agent in agentStore.agents"
        :key="agent.id"
        @click="navigateToAgent(agent.id)"
        :class="[
          'flex items-center gap-3 px-4 py-2.5 cursor-pointer rounded-lg mx-2 transition-colors',
          agent.id === activeAgentId
            ? 'bg-primary/10 text-primary font-medium'
            : 'hover:bg-muted text-muted-foreground hover:text-foreground'
        ]"
      >
        <div class="shrink-0 h-8 w-8 rounded-full bg-muted flex items-center justify-center text-xs font-semibold text-muted-foreground">
          {{ agent.name.charAt(0).toUpperCase() }}
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium truncate">{{ agent.name }}</p>
        </div>
      </li>
    </ul>

    <!-- Extra slot (e.g. "+ New Agent" button) -->
    <slot name="extra" />

    <CreateAgentModal v-model="showNewAgentModal" />
  </aside>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
