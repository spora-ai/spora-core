<script setup lang="ts">
/**
 * MailTemplateCreateModal — name + subject + body fields, Create / Cancel.
 */
import Icon from '@/components/ui/Icon.vue'
import type { MailTemplateCreateDraft } from '@/composables/useMailTemplates'

defineProps<{
  modelValue: boolean
  form: MailTemplateCreateDraft
  saving: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'update:name': [value: string]
  'update:subject': [value: string]
  'update:bodyText': [value: string]
  'update:bodyHtml': [value: string]
  create: []
}>()

function close(): void {
  emit('update:modelValue', false)
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="modelValue"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
      @click.self="close"
    >
      <div class="w-full max-w-lg mx-4 rounded-2xl border border-border bg-card shadow-xl flex flex-col max-h-[90vh]">
        <div class="px-6 py-4 border-b border-border flex items-center justify-between shrink-0">
          <h2 class="text-base font-semibold">Create Template</h2>
          <button
            @click="close"
            class="text-muted-foreground hover:text-foreground transition-colors"
          >
            <Icon name="x" class="h-5 w-5" />
          </button>
        </div>

        <div class="px-6 py-4 overflow-y-auto flex flex-col gap-4">
          <div class="flex flex-col gap-1.5">
            <label for="create-name" class="text-sm font-medium">Name</label>
            <input
              id="create-name"
              :value="form.name"
              @input="emit('update:name', ($event.target as HTMLInputElement).value)"
              type="text"
              placeholder="e.g. order_confirmation"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
            />
            <p class="text-xs text-muted-foreground">Unique identifier, no spaces.</p>
          </div>

          <div class="flex flex-col gap-1.5">
            <label for="create-subject" class="text-sm font-medium">Subject</label>
            <input
              id="create-subject"
              :value="form.subject"
              @input="emit('update:subject', ($event.target as HTMLInputElement).value)"
              type="text"
              placeholder="Email subject line"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
            />
          </div>

          <div class="flex flex-col gap-1.5">
            <label for="create-body-text" class="text-sm font-medium">Body (Plain Text)</label>
            <textarea
              id="create-body-text"
              :value="form.body_text"
              @input="emit('update:bodyText', ($event.target as HTMLTextAreaElement).value)"
              rows="4"
              placeholder="Plain text body…"
              class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring font-mono"
            />
          </div>

          <div class="flex flex-col gap-1.5">
            <label for="create-body-html" class="text-sm font-medium">Body (HTML)</label>
            <textarea
              id="create-body-html"
              :value="form.body_html"
              @input="emit('update:bodyHtml', ($event.target as HTMLTextAreaElement).value)"
              rows="4"
              placeholder="<p>HTML body…</p>"
              class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring font-mono"
            />
          </div>
        </div>

        <div class="px-6 py-4 border-t border-border flex items-center justify-end gap-3 shrink-0">
          <button
            @click="close"
            class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium shadow transition-colors hover:bg-muted"
          >
            Cancel
          </button>
          <button
            @click="emit('create')"
            :disabled="saving || !form.name.trim() || !form.subject.trim()"
            class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
          >
            {{ saving ? 'Creating…' : 'Create' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
