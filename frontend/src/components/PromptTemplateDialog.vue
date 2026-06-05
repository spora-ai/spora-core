<script setup lang="ts">
/**
 * PromptTemplateDialog — overlay dialog for creating prompt templates.
 *
 * Detects {{variable}} and {{variable:default}} patterns in the prompt text,
 * shows editable fields for user variables, and auto-fills system variables
 * (current_time, current_date, current_datetime).
 */
import { ref, computed, watch } from 'vue'
import Modal from '@/components/Modal.vue'
import { usePromptTemplatesStore } from '@/stores/promptTemplates'
import { ApiError } from '@/api/client'
import { detectVariables, buildTemplatePayload, seedSystemVariableValues } from '@/composables/usePromptTemplateVars'

const props = defineProps<{
  modelValue: boolean
  agentId: number
  /** The current prompt text to save as a template */
  initialPrompt: string
  /** The ID of the existing template being edited, if any */
  existingTemplateId?: number | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  /** Fired with the created PromptTemplateResource on success */
  saved: [template: import('@/types/promptTemplate').PromptTemplateResource]
}>()

const promptTemplatesStore = usePromptTemplatesStore()

// Form state

const formName = ref('')
const formPrompt = ref('')
const variableValues = ref<Record<string, string>>({})
const saving = ref(false)
const saveError = ref<string | null>(null)

const detectedVars = computed(() => detectVariables(formPrompt.value))

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      if (props.existingTemplateId) {
        const existing = promptTemplatesStore.templates.find(t => t.id === props.existingTemplateId)
        formName.value = existing?.name ?? ''
      } else {
        formName.value = ''
      }
      formPrompt.value = props.initialPrompt
      variableValues.value = {}
      saveError.value = null
      // Auto-fill system variables
      Object.assign(variableValues.value, seedSystemVariableValues())
    }
  },
)

// Submission

async function save(overwrite: boolean = false): Promise<void> {
  if (!formName.value.trim() || !formPrompt.value.trim()) return
  if (!Number.isFinite(props.agentId)) return

  saving.value = true
  saveError.value = null
  try {
    const payload = buildTemplatePayload({
      name: formName.value,
      promptTemplate: formPrompt.value,
      variables: detectedVars.value,
      values: variableValues.value,
    })

    let template;
    if (overwrite && props.existingTemplateId) {
      template = await promptTemplatesStore.updateTemplate(props.agentId, props.existingTemplateId, payload)
    } else {
      template = await promptTemplatesStore.createTemplate(props.agentId, payload)
    }

    emit('saved', template)
    emit('update:modelValue', false)
  } catch (e) {
    saveError.value = e instanceof ApiError ? e.message : 'Failed to save template.'
  } finally {
    saving.value = false
  }
}

function close(): void {
  emit('update:modelValue', false)
}
</script>

<template>
  <Modal
    :modelValue="modelValue"
    title="Save as Template"
    size="md"
    @update:modelValue="(v) => !v && close()"
    @close="close"
  >
    <div class="flex flex-col gap-4">
      <p v-if="saveError" role="alert" class="text-xs text-destructive">{{ saveError }}</p>

      <!-- Name -->
      <div class="flex flex-col gap-1.5">
        <label for="tmpl-name" class="text-sm font-medium">Template name</label>
        <input
          id="tmpl-name"
          v-model="formName"
          type="text"
          placeholder="My Research Template"
          autocomplete="off"
          class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
        />
      </div>

      <!-- Prompt template textarea -->
      <div class="flex flex-col gap-1.5">
        <label for="tmpl-prompt" class="text-sm font-medium">Prompt template</label>
        <textarea
          id="tmpl-prompt"
          v-model="formPrompt"
          rows="5"
          placeholder="Write your prompt here. Use {{variable}} or {{variable:default}} for variables."
          class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring resize-none"
        />
        <p class="text-xs text-muted-foreground">
          Use <code class="px-1 rounded bg-muted text-xs">{'{{'}variable{'}}'}</code> or
          <code class="px-1 rounded bg-muted text-xs">{'{{'}variable:default{'}}'}</code> for variables.
          System variables are auto-filled: <code class="px-1 rounded bg-muted text-xs">current_date</code>,
          <code class="px-1 rounded bg-muted text-xs">current_time</code>,
          <code class="px-1 rounded bg-muted text-xs">current_datetime</code>.
        </p>
      </div>

      <!-- Variable fields (only shown when variables are detected) -->
      <div v-if="detectedVars.length > 0" class="flex flex-col gap-3">
        <h3 class="text-sm font-semibold">Variables</h3>
        <div
          v-for="v in detectedVars"
          :key="v.key"
          class="flex flex-col gap-1.5"
        >
          <label :for="`tmpl-var-${v.key}`" class="text-sm font-medium flex items-center gap-2">
            {{ v.key }}
            <span v-if="v.isSystem" class="text-xs font-normal text-muted-foreground bg-muted px-1.5 py-0.5 rounded">system</span>
            <span v-if="v.defaultValue" class="text-xs font-normal text-muted-foreground">default: {{ v.defaultValue }}</span>
          </label>
          <input
            :id="`tmpl-var-${v.key}`"
            v-model="variableValues[v.key]"
            type="text"
            :placeholder="v.defaultValue || (v.isSystem ? '(auto-filled)' : '')"
            :disabled="v.isSystem"
            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm disabled:opacity-60 disabled:cursor-not-allowed focus:outline-none focus:ring-1 focus:ring-ring"
          />
        </div>
      </div>
    </div>

    <template #footer>
      <div class="flex justify-end gap-2 w-full">
        <button
          @click="close"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
        >
          Cancel
        </button>
        <template v-if="props.existingTemplateId">
          <button
            @click="save(false)"
            :disabled="saving || !formName.trim() || !formPrompt.trim()"
            class="inline-flex h-9 items-center justify-center rounded-lg bg-secondary px-4 text-sm font-medium text-secondary-foreground shadow-sm transition-colors hover:bg-secondary/80 disabled:pointer-events-none disabled:opacity-50"
          >
            Save as New
          </button>
          <button
            @click="save(true)"
            :disabled="saving || !formName.trim() || !formPrompt.trim()"
            class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
          >
            Update
          </button>
        </template>
        <template v-else>
          <button
            @click="save(false)"
            :disabled="saving || !formName.trim() || !formPrompt.trim()"
            class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
          >
            Save
          </button>
        </template>
      </div>
    </template>
  </Modal>
</template>
