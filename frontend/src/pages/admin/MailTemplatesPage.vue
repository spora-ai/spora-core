<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useMailTemplatesStore } from '@/stores/mailTemplates'
import { useToast } from '@/composables/useToast'

const SYSTEM_TEMPLATES = ['email_verification', 'password_reset', 'welcome']

const mailTemplates = useMailTemplatesStore()
const toast = useToast()

// ── UI State ────────────────────────────────────────────────────────────────

const editorForm = ref({ name: '', subject: '', body_text: '', body_html: '' })
const showCreateModal = ref(false)
const createForm = ref({ name: '', subject: '', body_text: '', body_html: '' })
const showPreview = ref(false)
const previewResult = ref<{ subject: string; body_text: string; body_html: string } | null>(null)
const previewParams = ref({ user_name: '', email: '', site_name: 'Spora', verification_link: '', reset_link: '' })
const previewLoading = ref(false)

// ── Computed ────────────────────────────────────────────────────────────────

const isSystemTemplate = computed(() =>
  mailTemplates.currentTemplate ? SYSTEM_TEMPLATES.includes(mailTemplates.currentTemplate.name) : false,
)

// ── Load ────────────────────────────────────────────────────────────────────

onMounted(async () => {
  try {
    await mailTemplates.fetchAll()
  } catch {
    toast.error('Failed to load mail templates.')
  }
})

async function selectTemplate(template: { id: number }): Promise<void> {
  try {
    const loaded = await mailTemplates.fetchOne(template.id)
    editorForm.value = {
      name: loaded.name,
      subject: loaded.subject,
      body_text: loaded.body_text ?? '',
      body_html: loaded.body_html ?? '',
    }
  } catch {
    toast.error('Failed to load template.')
  }
}

function goBack(): void {
  mailTemplates.currentTemplate = null
}

// ── Save ────────────────────────────────────────────────────────────────────

async function saveTemplate(): Promise<void> {
  if (!mailTemplates.currentTemplate) return
  try {
    const updated = await mailTemplates.update(mailTemplates.currentTemplate.id, {
      subject: editorForm.value.subject,
      body_text: editorForm.value.body_text || null,
      body_html: editorForm.value.body_html || null,
    })
    editorForm.value.name = updated.name
    toast.success('Template saved.')
  } catch (e) {
    toast.error(e instanceof Error ? e.message : 'Failed to save template.')
  }
}

// ── Delete ─────────────────────────────────────────────────────────────────

async function deleteTemplate(): Promise<void> {
  if (!mailTemplates.currentTemplate || isSystemTemplate.value) return
  try {
    await mailTemplates.remove(mailTemplates.currentTemplate.id)
    toast.success('Template deleted.')
    mailTemplates.currentTemplate = null
  } catch (e) {
    toast.error(e instanceof Error ? e.message : 'Failed to delete template.')
  }
}

// ── Create ─────────────────────────────────────────────────────────────────

async function createTemplate(): Promise<void> {
  if (!createForm.value.name.trim() || !createForm.value.subject.trim()) return
  try {
    const created = await mailTemplates.create({
      name: createForm.value.name.trim(),
      subject: createForm.value.subject.trim(),
      body_text: createForm.value.body_text || null,
      body_html: createForm.value.body_html || null,
    })
    showCreateModal.value = false
    createForm.value = { name: '', subject: '', body_text: '', body_html: '' }
    await selectTemplate(created)
    toast.success('Template created.')
  } catch (e) {
    toast.error(e instanceof Error ? e.message : 'Failed to create template.')
  }
}

// ── Preview ────────────────────────────────────────────────────────────────

function openPreview(): void {
  previewResult.value = null
  showPreview.value = true
}

async function runPreview(): Promise<void> {
  if (!mailTemplates.currentTemplate) return
  previewLoading.value = true
  try {
    const result = await mailTemplates.preview(
      mailTemplates.currentTemplate.name,
      previewParams.value as Record<string, string>,
    )
    previewResult.value = result as { subject: string; body_text: string; body_html: string }
  } catch (e) {
    toast.error(e instanceof Error ? e.message : 'Failed to generate preview.')
  } finally {
    previewLoading.value = false
  }
}

// ── Placeholder chips ──────────────────────────────────────────────────────

const PLACEHOLDERS = ['user_name', 'email', 'verification_link', 'reset_link', 'site_name']

function insertPlaceholder(ph: string): void {
  editorForm.value.body_text += `{{${ph}}}`
  editorForm.value.body_html += `{{${ph}}}`
}
</script>

<template>
  <div class="min-h-screen bg-background flex flex-col">
    <GlobalNavbar />

    <main class="flex-1 px-4 py-8">
      <div class="max-w-2xl mx-auto">

        <!-- Mobile back -->
        <div class="md:hidden mb-6">
          <button
            @click="$router.push({ name: 'settings-overview' })"
            class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium"
          >
            ← Overview
          </button>
        </div>

        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
          <div>
            <h1 class="text-lg font-semibold">Mail Templates</h1>
            <p class="text-sm text-muted-foreground mt-0.5">Manage transactional email templates.</p>
          </div>
          <button
            @click="showCreateModal = true"
            class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
          >
            + New Template
          </button>
        </div>

        <!-- List view -->
        <template v-if="!mailTemplates.currentTemplate">
          <div v-if="mailTemplates.loading" class="flex items-center justify-center py-12 text-sm text-muted-foreground">
            Loading…
          </div>
          <div v-else-if="mailTemplates.templates.length === 0" class="rounded-xl border border-border bg-card p-8 text-center text-sm text-muted-foreground">
            No templates yet.
          </div>
          <div v-else class="rounded-xl border border-border bg-card divide-y divide-border">
            <button
              v-for="t in mailTemplates.templates"
              :key="t.id"
              type="button"
              @click="selectTemplate(t)"
              class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-muted/50 transition-colors"
            >
              <div class="flex items-center gap-2">
                <span class="text-sm font-medium">{{ t.name }}</span>
                <span
                  v-if="SYSTEM_TEMPLATES.includes(t.name)"
                  class="text-xs rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400 px-1.5 py-0.5"
                >
                  System
                </span>
              </div>
              <Icon name="chevron-right" class="h-4 w-4 text-muted-foreground" />
            </button>
          </div>
        </template>

        <!-- Editor view -->
        <template v-else>

          <!-- Back + system warning -->
          <div class="flex flex-col gap-3 mb-4">
            <button
              @click="goBack"
              class="inline-flex h-8 items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition-colors w-fit"
            >
              <Icon name="chevron-left" class="h-4 w-4" />
              Back to list
            </button>

            <div v-if="isSystemTemplate" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-700/50 dark:bg-amber-900/20 p-3 text-sm text-amber-800 dark:text-amber-200">
              <Icon name="warning" class="h-4 w-4 mt-0.5 shrink-0 text-amber-500" />
              <span>This is a system template. Editing is allowed but changes may be overwritten on updates.</span>
            </div>
          </div>

          <div v-if="mailTemplates.loading" class="flex items-center justify-center py-12 text-sm text-muted-foreground">
            Loading template…
          </div>

          <template v-else>
            <!-- Name (read-only) -->
            <div class="rounded-xl border border-border bg-card p-5 flex flex-col gap-4 mb-4">
              <div class="flex flex-col gap-1.5">
                <label for="tmpl-name" class="text-sm font-medium">Name</label>
                <input
                  id="tmpl-name"
                  v-model="editorForm.name"
                  type="text"
                  disabled
                  class="w-full rounded-lg border border-border bg-muted px-3 py-2 text-sm text-muted-foreground cursor-not-allowed"
                />
              </div>

              <div class="flex flex-col gap-1.5">
                <label for="tmpl-subject" class="text-sm font-medium">Subject</label>
                <input
                  id="tmpl-subject"
                  v-model="editorForm.subject"
                  type="text"
                  placeholder="Email subject line"
                  class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                />
              </div>
            </div>

            <!-- Body -->
            <div class="rounded-xl border border-border bg-card p-5 flex flex-col gap-4 mb-4">
              <div class="flex flex-col gap-1.5">
                <label for="tmpl-body-text" class="text-sm font-medium">Body (Plain Text)</label>
                <textarea
                  id="tmpl-body-text"
                  v-model="editorForm.body_text"
                  rows="6"
                  placeholder="Plain text body…"
                  class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring font-mono"
                />
              </div>

              <div class="flex flex-col gap-1.5">
                <label for="tmpl-body-html" class="text-sm font-medium">Body (HTML)</label>
                <textarea
                  id="tmpl-body-html"
                  v-model="editorForm.body_html"
                  rows="6"
                  placeholder="<p>HTML body…</p>"
                  class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring font-mono"
                />
              </div>
            </div>

            <!-- Placeholders -->
            <div class="rounded-xl border border-border bg-card p-5 flex flex-col gap-3 mb-4">
              <p class="text-sm font-medium">Available Placeholders</p>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="ph in PLACEHOLDERS"
                  :key="ph"
                  type="button"
                  @click="insertPlaceholder(ph)"
                  class="rounded-full border border-border bg-muted px-3 py-1 text-xs font-mono text-muted-foreground hover:bg-muted/80 hover:text-foreground transition-colors"
                >
                  {{ `{{${ph}}}` }}
                </button>
              </div>
              <p class="text-xs text-muted-foreground">Click a placeholder to insert it into both body fields.</p>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-between gap-4">
              <button
                v-if="!isSystemTemplate"
                @click="deleteTemplate"
                :disabled="mailTemplates.saving"
                class="inline-flex h-9 items-center justify-center rounded-lg border border-destructive/30 bg-destructive/10 px-4 text-sm font-medium text-destructive shadow transition-colors hover:bg-destructive/20 disabled:pointer-events-none disabled:opacity-50"
              >
                {{ mailTemplates.saving ? 'Deleting…' : 'Delete' }}
              </button>
              <span v-else />

              <div class="flex items-center gap-3">
                <button
                  @click="openPreview"
                  class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium shadow transition-colors hover:bg-muted disabled:opacity-50"
                >
                  Preview
                </button>
                <button
                  @click="saveTemplate"
                  :disabled="mailTemplates.saving"
                  class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
                >
                  {{ mailTemplates.saving ? 'Saving…' : 'Save' }}
                </button>
              </div>
            </div>
          </template>
        </template>
      </div>
    </main>

    <!-- Create Template Modal -->
    <Teleport to="body">
      <div
        v-if="showCreateModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
        @click.self="showCreateModal = false"
      >
        <div class="w-full max-w-lg mx-4 rounded-2xl border border-border bg-card shadow-xl flex flex-col max-h-[90vh]">
          <div class="px-6 py-4 border-b border-border flex items-center justify-between shrink-0">
            <h2 class="text-base font-semibold">Create Template</h2>
            <button
              @click="showCreateModal = false"
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
                v-model="createForm.name"
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
                v-model="createForm.subject"
                type="text"
                placeholder="Email subject line"
                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
              />
            </div>

            <div class="flex flex-col gap-1.5">
              <label for="create-body-text" class="text-sm font-medium">Body (Plain Text)</label>
              <textarea
                id="create-body-text"
                v-model="createForm.body_text"
                rows="4"
                placeholder="Plain text body…"
                class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring font-mono"
              />
            </div>

            <div class="flex flex-col gap-1.5">
              <label for="create-body-html" class="text-sm font-medium">Body (HTML)</label>
              <textarea
                id="create-body-html"
                v-model="createForm.body_html"
                rows="4"
                placeholder="<p>HTML body…</p>"
                class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring font-mono"
              />
            </div>
          </div>

          <div class="px-6 py-4 border-t border-border flex items-center justify-end gap-3 shrink-0">
            <button
              @click="showCreateModal = false"
              class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium shadow transition-colors hover:bg-muted"
            >
              Cancel
            </button>
            <button
              @click="createTemplate"
              :disabled="mailTemplates.saving || !createForm.name.trim() || !createForm.subject.trim()"
              class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
            >
              {{ mailTemplates.saving ? 'Creating…' : 'Create' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Preview Modal -->
    <Teleport to="body">
      <div
        v-if="showPreview"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
        @click.self="showPreview = false"
      >
        <div class="w-full max-w-2xl mx-4 rounded-2xl border border-border bg-card shadow-xl flex flex-col max-h-[90vh]">
          <div class="px-6 py-4 border-b border-border flex items-center justify-between shrink-0">
            <h2 class="text-base font-semibold">Preview Template</h2>
            <button
              @click="showPreview = false"
              class="text-muted-foreground hover:text-foreground transition-colors"
            >
              <Icon name="x" class="h-5 w-5" />
            </button>
          </div>

          <div class="px-6 py-4 overflow-y-auto flex flex-col gap-4">
            <!-- Preview params -->
            <div class="grid grid-cols-2 gap-3">
              <div v-for="key in ['user_name', 'email', 'site_name', 'verification_link', 'reset_link']" :key="key" class="flex flex-col gap-1.5">
                <label :for="`preview-${key}`" class="text-xs font-medium">{{ key }}</label>
                <input
                  :id="`preview-${key}`"
                  v-model="previewParams[key as keyof typeof previewParams]"
                  type="text"
                  class="w-full rounded-lg border border-border bg-background px-3 py-1.5 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                  :placeholder="`{{${key}}}`"
                />
              </div>
            </div>

            <button
              @click="runPreview"
              :disabled="previewLoading"
              class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:opacity-50"
            >
              {{ previewLoading ? 'Rendering…' : 'Generate Preview' }}
            </button>

            <!-- Preview result -->
            <template v-if="previewResult">
              <div class="border-t border-border pt-4 flex flex-col gap-3">
                <div class="flex flex-col gap-1.5">
                  <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Subject</p>
                  <p class="text-sm font-medium">{{ previewResult.subject }}</p>
                </div>
                <div class="flex flex-col gap-1.5">
                  <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Plain Text</p>
                  <pre class="text-sm bg-muted rounded-lg p-3 whitespace-pre-wrap font-mono max-h-40 overflow-y-auto">{{ previewResult.body_text }}</pre>
                </div>
                <div v-if="previewResult.body_html" class="flex flex-col gap-1.5">
                  <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">HTML</p>
                  <pre class="text-sm bg-muted rounded-lg p-3 whitespace-pre-wrap font-mono max-h-40 overflow-y-auto">{{ previewResult.body_html }}</pre>
                </div>
              </div>
            </template>
          </div>

          <div class="px-6 py-4 border-t border-border flex items-center justify-end shrink-0">
            <button
              @click="showPreview = false"
              class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium shadow transition-colors hover:bg-muted"
            >
              Close
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>