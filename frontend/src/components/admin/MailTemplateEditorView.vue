<script setup lang="ts">
/**
 * MailTemplateEditorView — name (read-only) + subject + body text/html +
 * placeholder chips + save/preview/delete actions.
 */
import Icon from '@/components/ui/Icon.vue'
import { formatPlaceholder, type MailTemplateDraft } from '@/composables/useMailTemplates'

defineProps<{
  form: MailTemplateDraft
  loading: boolean
  saving: boolean
  isSystem: boolean
  placeholders: readonly string[]
}>()

const emit = defineEmits<{
  back: []
  save: []
  delete: []
  preview: []
  'update:subject': [value: string]
  'update:bodyText': [value: string]
  'update:bodyHtml': [value: string]
  'insert-placeholder': [ph: string]
}>()
</script>

<template>
  <div>
    <div class="flex flex-col gap-3 mb-4">
      <button
        @click="emit('back')"
        class="inline-flex h-8 items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition-colors w-fit"
      >
        <Icon name="chevron-left" class="h-4 w-4" />
        Back to list
      </button>

      <div v-if="isSystem" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-700/50 dark:bg-amber-900/20 p-3 text-sm text-amber-800 dark:text-amber-200">
        <Icon name="warning" class="h-4 w-4 mt-0.5 shrink-0 text-amber-500" />
        <span>This is a system template and cannot be deleted.</span>
      </div>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12 text-sm text-muted-foreground">
      Loading template…
    </div>

    <template v-else>
      <div class="rounded-xl border border-border bg-card p-5 flex flex-col gap-4 mb-4">
        <div class="flex flex-col gap-1.5">
          <label for="tmpl-name" class="text-sm font-medium">Name</label>
          <input
            id="tmpl-name"
            :value="form.name"
            type="text"
            disabled
            class="w-full rounded-lg border border-border bg-muted px-3 py-2 text-sm text-muted-foreground cursor-not-allowed"
          />
        </div>

        <div class="flex flex-col gap-1.5">
          <label for="tmpl-subject" class="text-sm font-medium">Subject</label>
          <input
            id="tmpl-subject"
            :value="form.subject"
            @input="emit('update:subject', ($event.target as HTMLInputElement).value)"
            type="text"
            placeholder="Email subject line"
            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
          />
        </div>
      </div>

      <div class="rounded-xl border border-border bg-card p-5 flex flex-col gap-4 mb-4">
        <div class="flex flex-col gap-1.5">
          <label for="tmpl-body-text" class="text-sm font-medium">Body (Plain Text)</label>
          <textarea
            id="tmpl-body-text"
            :value="form.body_text"
            @input="emit('update:bodyText', ($event.target as HTMLTextAreaElement).value)"
            rows="6"
            placeholder="Plain text body…"
            class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring font-mono"
          />
        </div>

        <div class="flex flex-col gap-1.5">
          <label for="tmpl-body-html" class="text-sm font-medium">Body (HTML)</label>
          <textarea
            id="tmpl-body-html"
            :value="form.body_html"
            @input="emit('update:bodyHtml', ($event.target as HTMLTextAreaElement).value)"
            rows="6"
            placeholder="<p>HTML body…</p>"
            class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring font-mono"
          />
        </div>
      </div>

      <div class="rounded-xl border border-border bg-card p-5 flex flex-col gap-3 mb-4">
        <p class="text-sm font-medium">Available Placeholders</p>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="ph in placeholders"
            :key="ph"
            type="button"
            @click="emit('insert-placeholder', ph)"
            class="rounded-full border border-border bg-muted px-3 py-1 text-xs font-mono text-muted-foreground hover:bg-muted/80 hover:text-foreground transition-colors"
          >
            {{ formatPlaceholder(ph) }}
          </button>
        </div>
        <p class="text-xs text-muted-foreground">Click a placeholder to insert it into both body fields.</p>
      </div>

      <div class="flex items-center justify-between gap-4">
        <button
          v-if="!isSystem"
          @click="emit('delete')"
          :disabled="saving"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-destructive/30 bg-destructive/10 px-4 text-sm font-medium text-destructive shadow transition-colors hover:bg-destructive/20 disabled:pointer-events-none disabled:opacity-50"
        >
          {{ saving ? 'Deleting…' : 'Delete' }}
        </button>
        <span v-else />

        <div class="flex items-center gap-3">
          <button
            @click="emit('preview')"
            class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium shadow transition-colors hover:bg-muted disabled:opacity-50"
          >
            Preview
          </button>
          <button
            @click="emit('save')"
            :disabled="saving"
            class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
          >
            {{ saving ? 'Saving…' : 'Save' }}
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
