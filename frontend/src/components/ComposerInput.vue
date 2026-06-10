<script setup lang="ts">
/**
 * ComposerInput — prompt composer with template selection, scheduling, and task submission.
 * Used on the AgentPage to create new tasks.
 */
import { ref, computed, nextTick, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAgentStore } from '@/stores/agent'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import { useLlmPreferencesStore } from '@/stores/llmPreferencesStore'
import { usePromptTemplatesStore } from '@/stores/promptTemplates'
import SharedScheduleEditor from '@/components/shared/ScheduleEditor/index.vue'
import PromptTemplateDialog from '@/components/PromptTemplateDialog.vue'
import { isSubmitKeystroke } from '@/composables/useComposerInput'
import { useComposerSubmit } from '@/composables/useComposerSubmit'
import { useComposerTemplate } from '@/composables/useComposerTemplate'
import Icon from '@/components/ui/Icon.vue'

const props = defineProps<{
  agentId: number
  disabled?: boolean
}>()

const router = useRouter()
const agentStore = useAgentStore()
const llmConfigsStore = useLlmConfigsStore()
const preferenceStore = useLlmPreferencesStore()
const promptTemplatesStore = usePromptTemplatesStore()

const currentLlmConfig = computed(() =>
  llmConfigsStore.configs.find(c => c.id === agentStore.currentAgent?.llm_driver_config_id)
)
const configName = computed(() => currentLlmConfig.value?.name ?? 'Custom LLM config')

const promptText = computed({
  get: () => agentStore.getComposerDraft(props.agentId).promptText,
  set: (value: string) => {
    agentStore.getComposerDraft(props.agentId).promptText = value
  },
})

const textareaRef = ref<HTMLTextAreaElement | null>(null)
const showScheduleEditor = ref(false)

function adjustTextareaHeight(): void {
  nextTick(() => {
    if (!textareaRef.value) return
    textareaRef.value.style.height = 'auto'
    textareaRef.value.style.height = Math.min(textareaRef.value.scrollHeight, 300) + 'px'
  })
}

watch(promptText, adjustTextareaHeight)

const { submitting, error: submitError, submit } = useComposerSubmit(props.agentId)
const template = useComposerTemplate(props.agentId, (v) => { promptText.value = v })
const composerError = computed(() => submitError.value || template.error.value)

function onComposerKeydown(e: KeyboardEvent): void {
  if (isSubmitKeystroke(e)) {
    e.preventDefault()
    submit(promptText.value)
  }
}

function onScheduleSaved(): void {
  showScheduleEditor.value = false
  promptText.value = ''
  template.selectedTemplateId.value = null
}
</script>

<template>
  <div class="px-6 py-6 border-b border-border border-b-2">
    <div class="relative flex flex-col w-full rounded-2xl border border-border bg-card shadow-sm transition-all focus-within:ring-2 focus-within:ring-primary/20">

      <div class="flex items-center justify-between px-3 py-2 border-b border-muted bg-muted/20 rounded-t-2xl">
        <div class="flex items-center gap-2">
          <template v-if="promptTemplatesStore.templates.length > 0">
            <select
              v-model="template.selectedTemplateId.value"
              @change="template.onTemplateChange(template.selectedTemplateId.value)"
              class="h-8 rounded-[8px] border border-border bg-background px-3 pr-8 text-xs font-medium text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring appearance-none cursor-pointer"
            >
              <option :value="null">Choose a template…</option>
              <option v-for="tmpl in promptTemplatesStore.templates" :key="tmpl.id" :value="tmpl.id">
                {{ tmpl.name }}
              </option>
            </select>
          </template>

          <button
            v-if="template.selectedTemplateId.value"
            @click="template.deleteSelectedTemplate"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground hover:bg-destructive/10 hover:text-destructive transition-colors"
            title="Delete template"
          >
            <Icon name="trash" class="h-3.5 w-3.5" />
          </button>

          <button
            v-if="promptText.trim()"
            @click="template.openSaveDialog"
            class="inline-flex h-8 items-center gap-1.5 px-3 rounded-[8px] text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
            title="Save prompt as template"
          >
            <Icon name="star" class="h-3.5 w-3.5" />
            <span>Save</span>
          </button>
        </div>

        <button
          @click="showScheduleEditor = true"
          class="inline-flex h-8 items-center gap-1.5 px-3 rounded-[8px] border border-border text-xs font-medium bg-background text-muted-foreground hover:border-muted-foreground hover:text-foreground transition-colors shadow-sm"
          title="Schedule a run"
        >
          <Icon name="clock" class="h-3 w-3" />
          Schedule
        </button>
      </div>

      <div class="px-2 pt-2 relative border-0">
        <textarea
          ref="textareaRef"
          v-model="promptText"
          @keydown="onComposerKeydown"
          :disabled="submitting || disabled"
          placeholder="Message this agent... (Cmd+Enter to submit)"
          class="w-full resize-none bg-transparent px-3 py-2 text-sm md:text-base placeholder:text-muted-foreground focus:outline-none border-0 ring-0 focus:ring-0 focus-visible:ring-0 focus-visible:ring-offset-0 disabled:cursor-not-allowed"
          style="min-height: 56px; overflow-y: auto;"
        />
        <p v-if="composerError" role="alert" class="px-3 pb-2 text-xs text-destructive">{{ composerError }}</p>
      </div>

      <div class="flex items-center justify-end px-4 pb-3 pt-1">
        <button
          @click="submit(promptText)"
          :disabled="submitting || !promptText.trim() || disabled"
          class="shrink-0 h-9 w-9 rounded-full bg-primary text-primary-foreground shadow-md hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:pointer-events-none flex items-center justify-center z-10"
        >
          <Icon name="arrow-right" />
        </button>
      </div>
    </div>

    <template v-if="agentStore.currentAgent">
      <div class="px-4 pb-3 pt-1 flex flex-wrap items-center gap-3 text-[11px] font-medium text-muted-foreground">
        <button
          @click="router.push({ name: 'agent-settings', params: { id: agentId } })"
          class="flex items-center gap-1 hover:text-foreground transition-colors cursor-pointer"
          title="Go to agent settings"
        >
          <Icon name="computer" class="h-3 w-3 shrink-0" />
          <span v-if="agentStore.currentAgent.llm_driver_config_id">
            {{ configName }}
          </span>
          <span v-else-if="preferenceStore.preference">
            {{ preferenceStore.preference.config.name }} (preferred)
          </span>
          <span v-else>
            Global default
          </span>
        </button>

        <button
          @click="router.push({ name: 'agent-settings', params: { id: agentId } })"
          class="flex items-center gap-1 hover:text-foreground transition-colors cursor-pointer"
          title="Go to agent tools"
        >
          <Icon name="tools" class="h-3 w-3 shrink-0" />
          <span>{{ agentStore.currentAgent.tools.length }} tools</span>
        </button>

        <button
          @click="router.push({ name: 'agent-settings', params: { id: agentId } })"
          class="flex items-center gap-1 hover:text-foreground transition-colors cursor-pointer"
          title="Go to agent settings"
        >
          <Icon name="zap" class="h-3 w-3 shrink-0" />
          <span>Max {{ agentStore.currentAgent.max_steps }} steps</span>
        </button>
      </div>
    </template>
  </div>

  <PromptTemplateDialog
    v-model="template.showSaveDialog.value"
    :agent-id="agentId"
    :initial-prompt="promptText"
    :existing-template-id="template.selectedTemplateId.value"
    @saved="template.onTemplateSaved"
  />

  <SharedScheduleEditor
    v-model="showScheduleEditor"
    :agentId="agentId"
    :initialData="template.selectedTemplateId.value !== null ? { template_id: template.selectedTemplateId.value, raw_prompt: promptText.trim() || undefined } : { raw_prompt: promptText.trim() || undefined }"
    @saved="onScheduleSaved"
    @closed="showScheduleEditor = false"
  />
</template>

<style scoped>
textarea {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
textarea::-webkit-scrollbar {
  display: none;
}
</style>
