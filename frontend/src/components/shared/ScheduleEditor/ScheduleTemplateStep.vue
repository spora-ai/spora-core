<script setup lang="ts">
/**
 * ScheduleTemplateStep — Step 1. Template picker + inline-create form + prompt textarea.
 */
import { computed } from 'vue'
import { SCHEDULE_PROMPT_VARIABLES, wrapPromptVariable } from '@/composables/useScheduleWizard'
import type { ScheduleForm } from '@/composables/useScheduleForm'
import { usePromptTemplatesStore } from '@/stores/promptTemplates'
import Icon from '@/components/ui/Icon.vue'

const props = defineProps<{ form: ScheduleForm }>()

const promptTemplatesStore = usePromptTemplatesStore()

function varToken(token: string): string {
  return wrapPromptVariable(token)
}

// v-model proxies — Vue's v-model on a nested ref produces a type mismatch
// (Ref<string | undefined> vs the native input's string|number), so wrap each
// editable ref in a writable computed.
const templateIdModel = computed({
  get: () => props.form.templateId.value,
  set: (v) => { props.form.templateId.value = typeof v === 'string' ? Number(v) : v },
})
const newTemplateNameModel = computed({
  get: () => props.form.newTemplateName.value,
  set: (v) => { props.form.newTemplateName.value = v ?? '' },
})
const rawPromptModel = computed({
  get: () => props.form.rawPrompt.value,
  set: (v) => { props.form.rawPrompt.value = v ?? '' },
})
</script>

<template>
  <div class="flex flex-col gap-4">
    <p class="text-sm text-muted-foreground">
      Choose an existing prompt template or create a new one. A template is required.
    </p>

    <div class="flex flex-col gap-1.5">
      <label for="schedule-template" class="text-sm font-medium">Prompt template</label>
      <select
        id="schedule-template"
        v-model="templateIdModel"
        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
      >
        <option :value="null">— Select a template —</option>
        <option
          v-for="tmpl in promptTemplatesStore.templates"
          :key="tmpl.id"
          :value="tmpl.id"
        >
          {{ tmpl.name }}
        </option>
        <option :value="-1">+ Create new template…</option>
      </select>
    </div>

    <div
      v-if="props.form.templateId.value === -1"
      class="flex flex-col gap-2 rounded-lg border border-dashed border-border bg-muted/20 p-3"
    >
      <div class="flex items-center gap-2">
        <Icon name="plus" class="h-4 w-4 text-muted-foreground shrink-0" />
        <span class="text-sm font-medium">New template</span>
      </div>
      <input
        v-model="newTemplateNameModel"
        type="text"
        placeholder="Template name, e.g. Daily Digest"
        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
      />
      <p class="text-xs text-muted-foreground">
        The template will be saved and used for this scheduled run.
      </p>
    </div>

    <div class="flex flex-col gap-1.5">
      <label for="schedule-prompt" class="text-sm font-medium flex items-center gap-2">
        <span>Prompt</span>
        <span
          v-if="form.templateId.value !== null && form.templateId.value !== -1"
          class="text-xs font-normal text-muted-foreground bg-muted px-1.5 py-0.5 rounded"
        >from template</span>
      </label>
      <textarea
        id="schedule-prompt"
        v-model="rawPromptModel"
        rows="5"
        :disabled="form.templateId.value !== null && form.templateId.value !== -1"
        placeholder="Instructions for the agent"
        class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-60 disabled:cursor-not-allowed"
      />
      <p class="text-xs text-muted-foreground">
        Available runtime variables:
        <code
          v-for="v in SCHEDULE_PROMPT_VARIABLES"
          :key="v.token"
          class="mx-0.5 px-1 rounded bg-muted text-[10px]"
        >{{ varToken(v.token) }}</code>.
      </p>
    </div>
  </div>
</template>
