<script setup lang="ts">
import { ref, computed, nextTick, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAgentStore } from '@/stores/agent'
import { usePromptTemplatesStore } from '@/stores/promptTemplates'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import { useTaskStore } from '@/stores/tasks'
import { ApiError } from '@/api/client'
import SharedScheduleEditor from '@/components/shared/SharedScheduleEditor.vue'
import PromptTemplateDialog from '@/components/PromptTemplateDialog.vue'

const props = defineProps<{
  llmUnconfigured: boolean
  agentId: number
}>()

const router = useRouter()
const agentStore = useAgentStore()
const taskStore = useTaskStore()
const promptTemplatesStore = usePromptTemplatesStore()
const llmConfigsStore = useLlmConfigsStore()

// Derived states for LLM & Setup
const currentLlmConfig = computed(() =>
  llmConfigsStore.configs.find(c => c.id === agentStore.currentAgent?.llm_driver_config_id)
)
const configName = computed(() => currentLlmConfig.value?.name ?? 'Custom LLM config')

// Composer State
const promptText = ref('')
const composerError = ref<string | null>(null)
const submitting = ref(false)
const selectedTemplateId = ref<number | null>(null)
const showScheduleEditor = ref(false)
const showTemplateDialog = ref(false)

const textareaRef = ref<HTMLTextAreaElement | null>(null)

// Auto-resize logic
function adjustTextareaHeight() {
  nextTick(() => {
    if (!textareaRef.value) return
    textareaRef.value.style.height = 'auto'
    textareaRef.value.style.height = Math.min(textareaRef.value.scrollHeight, 300) + 'px'
  })
}

// Watch prompt text to dynamically resize and trim if needed
watch(promptText, () => {
  adjustTextareaHeight()
})

async function submitPrompt(): Promise<void> {
  const text = promptText.value.trim()
  if (!text) return
  composerError.value = null
  submitting.value = true
  try {
    const task = await taskStore.createTaskForAgent(props.agentId, text)
    promptText.value = ''
    adjustTextareaHeight() // Reset height
    router.push({ name: 'task', params: { id: task.id } })
  } catch (e) {
    composerError.value = e instanceof ApiError ? e.message : 'Failed to start task.'
  } finally {
    submitting.value = false
  }
}

function onComposerKeydown(e: KeyboardEvent): void {
  if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
    e.preventDefault()
    submitPrompt()
  } else if (e.key === 'Enter' && !e.shiftKey) {
    // Optionally submit on Enter (without shift), typically standard for AI chat but user had Cmd+Enter
    // The previous implementation used Cmd+Enter. Let's keep it as Cmd+Enter to preserve features.
  }
}

function onTemplateChange(templateId: number | null): void {
  selectedTemplateId.value = templateId
  if (templateId === null) {
      promptText.value = ''
      return
  }
  const tmpl = promptTemplatesStore.templates.find((t) => t.id === templateId)
  if (tmpl) {
    let text = tmpl.prompt_template
    const now = new Date()
    const sysVars: Record<string, string> = {
      current_date: now.toISOString().split('T')[0],
      current_time: now.toTimeString().slice(0, 5),
      current_datetime: now.toISOString().slice(0, 16),
    }

    text = text.replace(/\{\{(\w+)(?::([^}]*))?\}\}/g, (match: string, key: string, defaultVal?: string) => {
      if (sysVars[key] !== undefined) return sysVars[key]
      const v = tmpl.variables?.find(v => v.key === key)
      if (v?.default_value) return v.default_value
      if (defaultVal !== undefined) return defaultVal
      return match
    })

    promptText.value = text
  }
}

async function deleteSelectedTemplate(): Promise<void> {
  if (selectedTemplateId.value === null) return
  if (!confirm('Are you sure you want to delete this template?')) return
  
  try {
    await promptTemplatesStore.deleteTemplate(props.agentId, selectedTemplateId.value)
    selectedTemplateId.value = null
    promptText.value = ''
  } catch (e) {
    composerError.value = e instanceof ApiError ? e.message : 'Failed to delete template.'
  }
}

function saveAsTemplate(): void {
  if (!Number.isFinite(props.agentId)) return
  showTemplateDialog.value = true
}

function onTemplateSaved(template: { id: number; prompt_template: string }): void {
  selectedTemplateId.value = template.id
}

function onScheduleSaved(): void {
  showScheduleEditor.value = false
  promptText.value = ''
  selectedTemplateId.value = null
}
</script>

<template>
  <div class="bg-background shrink-0 flex flex-col relative z-20">
    <!-- Top toolbar (Hamburger for mobile, Title, Settings gear) -->
    <div class="px-6 py-4 flex flex-col md:flex-row items-stretch md:items-center justify-between gap-4 shrink-0 border-b border-border text-foreground">
      <div class="flex items-center gap-4 flex-1 min-w-0">
        <!-- Mobile: hamburger + back button -->
        <button
          @click="router.push({ name: 'dashboard' })"
          class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors lg:hidden"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
          </svg>
        </button>

        <div class="flex-1 min-w-0">
          <h1 class="text-xl font-bold truncate">
            {{ agentStore.currentAgent?.name ?? 'Loading…' }}
          </h1>
          <p v-if="agentStore.currentAgent?.description" class="text-sm text-muted-foreground truncate mt-0.5">
            {{ agentStore.currentAgent.description }}
          </p>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <!-- Settings link -->
        <button
          @click="router.push({ name: 'agent-settings', params: { id: agentId } })"
          class="flex items-center justify-center h-9 w-9 rounded-full text-muted-foreground hover:text-foreground hover:bg-muted font-medium transition-colors"
          title="Agent Settings"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
        </button>

        <!-- Schedule runs link -->
        <button
          @click="router.push({ name: 'scheduled-runs', params: { id: agentId } })"
          class="flex items-center justify-center h-9 w-9 rounded-full text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
          title="Scheduled Runs"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Agent Setup Banner (if llmUnconfigured) -->
    <div
      v-if="llmUnconfigured"
      class="mx-6 mt-4 rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 px-4 py-3 flex items-start gap-3"
    >
      <svg class="h-5 w-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
      </svg>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">LLM not configured</p>
        <p class="text-xs text-amber-700 dark:text-amber-300 mt-0.5">
          Add your API key before running tasks.
        </p>
      </div>
      <button
        @click="router.push({ name: 'agent-settings', params: { id: agentId } })"
        class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg bg-amber-600 hover:bg-amber-700 px-3 text-xs font-medium text-white transition-colors"
      >
        Configure
      </button>
    </div>

    <template v-if="agentStore.currentAgent">
      <!-- unified Composer Area -->
      <div class="px-6 py-6 border-b border-border border-b-2">
        <div class="relative flex flex-col w-full rounded-2xl border border-border bg-card shadow-sm transition-all focus-within:ring-2 focus-within:ring-primary/20">
          
          <!-- Top Composer Toolbar (Templates) -->
          <div class="flex items-center justify-between px-3 py-2 border-b border-muted bg-muted/20 rounded-t-2xl">
            <div class="flex items-center gap-2">
              <template v-if="promptTemplatesStore.templates.length > 0">
                <select
                  v-model="selectedTemplateId"
                  @change="onTemplateChange(selectedTemplateId)"
                  class="h-8 rounded-[8px] border border-border bg-background px-3 pr-8 text-xs font-medium text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring appearance-none cursor-pointer"
                >
                  <option :value="null">Choose a template…</option>
                  <option v-for="tmpl in promptTemplatesStore.templates" :key="tmpl.id" :value="tmpl.id">
                    {{ tmpl.name }}
                  </option>
                </select>
              </template>

              <!-- Delete Template Action -->
              <button
                v-if="selectedTemplateId"
                @click="deleteSelectedTemplate"
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground hover:bg-destructive/10 hover:text-destructive transition-colors"
                title="Delete template"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>

              <button
                v-if="promptText.trim()"
                @click="saveAsTemplate"
                class="inline-flex h-8 items-center gap-1.5 px-3 rounded-[8px] text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
                title="Save prompt as template"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                   <path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                </svg>
                <span>Save</span>
              </button>

            </div>

             <!-- Schedule Action -->
            <button
               @click="showScheduleEditor = true"
               class="inline-flex h-8 items-center gap-1.5 px-3 rounded-[8px] border border-border text-xs font-medium bg-background text-muted-foreground hover:border-muted-foreground hover:text-foreground transition-colors shadow-sm"
               title="Schedule a run"
             >
               <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                 <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
               </svg>
               Schedule
             </button>
          </div>

          <!-- Auto-expanding Textarea -->
          <div class="px-2 pt-2 relative border-0">
             <textarea
               ref="textareaRef"
               v-model="promptText"
               @keydown="onComposerKeydown"
               placeholder="Message this agent... (Cmd+Eneter to submit)"
               class="w-full resize-none bg-transparent px-3 py-2 text-sm md:text-base placeholder:text-muted-foreground focus:outline-none border-0 ring-0 focus:ring-0 focus-visible:ring-0 focus-visible:ring-offset-0 disabled:cursor-not-allowed"
               style="min-height: 56px; overflow-y: auto;"
             />
             <p v-if="composerError" role="alert" class="px-3 pb-2 text-xs text-destructive">{{ composerError }}</p>
          </div>

          <!-- Bottom Composer Toolbar (Metadata & Submit) -->
          <div class="flex items-center justify-between px-4 pb-3 pt-1">
             <!-- Agent Meta Strip -->
             <div class="flex flex-wrap items-center gap-3 text-[11px] font-medium text-muted-foreground">
                <!-- LLM info -->
                <button
                  @click="router.push({ name: 'agent-settings', params: { id: agentId } })"
                  class="flex items-center gap-1 hover:text-foreground transition-colors cursor-pointer"
                  title="Go to agent settings"
                >
                  <svg class="h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                  </svg>
                  <span v-if="agentStore.currentAgent?.llm_driver_config_id">
                    {{ configName }}
                  </span>
                  <span v-else>
                    {{ llmConfigsStore.configs.find(c => c.is_default)?.name ?? 'Global default' }}
                  </span>
                </button>

                <!-- Tools count -->
                <button
                  @click="router.push({ name: 'agent-settings', params: { id: agentId } })"
                  class="flex items-center gap-1 hover:text-foreground transition-colors cursor-pointer"
                  title="Go to agent tools"
                >
                  <svg class="h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
                  </svg>
                  <span>{{ agentStore.currentAgent.tools.length }} tools</span>
                </button>

                <!-- Max steps -->
                <button
                  @click="router.push({ name: 'agent-settings', params: { id: agentId } })"
                  class="flex items-center gap-1 hover:text-foreground transition-colors cursor-pointer"
                  title="Go to agent settings"
                >
                  <svg class="h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                  </svg>
                  <span>Max {{ agentStore.currentAgent.max_steps }} steps</span>
                </button>
             </div>

             <!-- Submit Button -->
             <button
               @click="submitPrompt"
               :disabled="submitting || !promptText.trim()"
               class="shrink-0 h-9 w-9 rounded-full bg-primary text-primary-foreground shadow-md hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:pointer-events-none flex items-center justify-center z-10"
             >
               <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                 <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12h15m0 0l-6.75-6.75M19.5 12l-6.75 6.75" />
               </svg>
             </button>
          </div>
        </div>
      </div>
    </template>
    
    <!-- Template Dialog Modal -->
    <PromptTemplateDialog
      v-model="showTemplateDialog"
      :agent-id="agentId"
      :initial-prompt="promptText"
      :existing-template-id="selectedTemplateId"
      @saved="onTemplateSaved"
    />

    <!-- Schedule Editor Modal -->
    <SharedScheduleEditor
      v-model="showScheduleEditor"
      :agentId="agentId"
      :initialData="selectedTemplateId !== null ? { template_id: selectedTemplateId, raw_prompt: promptText.trim() || undefined } : { raw_prompt: promptText.trim() || undefined }"
      @saved="onScheduleSaved"
      @closed="showScheduleEditor = false"
    />
  </div>
</template>

<style scoped>
/* Minimal override for textarea defaults to ensure pure styling */
textarea {
  -ms-overflow-style: none; 
  scrollbar-width: none; 
}
textarea::-webkit-scrollbar {
  display: none;
}
</style>
