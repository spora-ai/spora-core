/**
 * useComposerTemplate — template selection + save-as-template flow for the
 * ComposerInput. Keeps the selected template id and the save-dialog flag.
 * The prompt-text mutation is owned by the caller (it goes through the
 * agent's per-agent draft).
 */
import { ref } from 'vue'
import { usePromptTemplatesStore } from '@/stores/promptTemplates'
import { useConfirmDialog } from '@/composables/useConfirmDialog'
import { ApiError } from '@/api/client'
import { buildPromptFromTemplate } from '@/composables/useComposerInput'

export function useComposerTemplate(agentId: number, setPrompt: (v: string) => void) {
  const promptTemplatesStore = usePromptTemplatesStore()
  const { confirm } = useConfirmDialog()

  const selectedTemplateId = ref<number | null>(null)
  const showSaveDialog = ref(false)
  const error = ref<string | null>(null)

  function onTemplateChange(templateId: number | null): void {
    selectedTemplateId.value = templateId
    if (templateId === null) {
      setPrompt('')
      return
    }
    const tmpl = promptTemplatesStore.templates.find((t) => t.id === templateId)
    if (!tmpl) return
    setPrompt(buildPromptFromTemplate(tmpl.prompt_template, tmpl.variables))
  }

  async function deleteSelectedTemplate(): Promise<void> {
    if (selectedTemplateId.value === null) return
    if (!await confirm('Are you sure you want to delete this template?')) return
    try {
      await promptTemplatesStore.deleteTemplate(agentId, selectedTemplateId.value)
      selectedTemplateId.value = null
      setPrompt('')
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to delete template.'
    }
  }

  function openSaveDialog(): void {
    if (!Number.isFinite(agentId)) return
    showSaveDialog.value = true
  }

  function onTemplateSaved(template: { id: number }): void {
    selectedTemplateId.value = template.id
  }

  return {
    selectedTemplateId,
    showSaveDialog,
    error,
    onTemplateChange,
    deleteSelectedTemplate,
    openSaveDialog,
    onTemplateSaved,
  }
}
