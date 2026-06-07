<script setup lang="ts">
/**
 * MailTemplatesPage — admin mail template editor.
 * Route: /admin/mail-templates
 *
 * Thin shell: admin guard + fetchAll on mount + 4-way v-if switch
 * between list / editor / create / preview. All state and actions live
 * in `useMailTemplateEditor`.
 */
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useMailTemplateEditor } from '@/composables/useMailTemplateEditor'
import { useToast } from '@/composables/useToast'
import GlobalNavbar from '@/components/GlobalNavbar.vue'
import MailTemplateListView from '@/components/admin/MailTemplateListView.vue'
import MailTemplateEditorView from '@/components/admin/MailTemplateEditorView.vue'
import MailTemplateCreateModal from '@/components/admin/MailTemplateCreateModal.vue'
import MailTemplatePreviewModal from '@/components/admin/MailTemplatePreviewModal.vue'

const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const editor = useMailTemplateEditor()

// Writable computeds let the v-model on the modals round-trip through
// the composable's refs.
const showCreateModal = computed({
  get: () => editor.showCreateModal.value,
  set: (v: boolean) => { editor.showCreateModal.value = v },
})
const showPreview = computed({
  get: () => editor.showPreview.value,
  set: (v: boolean) => { editor.showPreview.value = v },
})

onMounted(async () => {
  if (!auth.user?.roles?.includes('ADMIN')) {
    router.replace({ name: 'settings-overview' })
    return
  }
  try {
    await editor.store.fetchAll()
  } catch {
    toast.error('Failed to load mail templates.')
  }
})
</script>

<template>
  <div class="min-h-screen bg-background flex flex-col">
    <GlobalNavbar />

    <main class="flex-1 px-4 py-8">
      <div class="max-w-2xl mx-auto">
        <div class="flex items-center justify-between mb-6">
          <div>
            <h1 class="text-lg font-semibold">Mail Templates</h1>
            <p class="text-sm text-muted-foreground mt-0.5">Manage transactional email templates.</p>
          </div>
          <button
            v-if="!editor.store.currentTemplate"
            @click="editor.showCreateModal.value = true"
            class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
          >
            + New Template
          </button>
        </div>

        <MailTemplateListView
          v-if="!editor.store.currentTemplate"
          :templates="editor.store.templates"
          :loading="editor.store.loading"
          @select="editor.selectTemplate"
          @create="editor.showCreateModal.value = true"
        />

        <MailTemplateEditorView
          v-else
          :form="editor.editorForm.value"
          :placeholders="editor.placeholders"
          :is-system="editor.isSystemTemplate.value"
          :saving="editor.store.saving"
          :loading="editor.store.loading"
          @back="editor.goBack"
          @save="editor.saveTemplate"
          @delete="editor.deleteTemplate"
          @preview="editor.openPreview"
          @update:subject="(v) => (editor.editorForm.value.subject = v)"
          @update:bodyText="(v) => (editor.editorForm.value.body_text = v)"
          @update:bodyHtml="(v) => (editor.editorForm.value.body_html = v)"
          @insert-placeholder="editor.insertPlaceholder"
        />
      </div>
    </main>

    <MailTemplateCreateModal
v-model="showCreateModal"
      :form="editor.createForm.value"
      :saving="editor.store.saving"
      @update:name="(v) => (editor.createForm.value.name = v)"
      @update:subject="(v) => (editor.createForm.value.subject = v)"
      @update:bodyText="(v) => (editor.createForm.value.body_text = v)"
      @update:bodyHtml="(v) => (editor.createForm.value.body_html = v)"
      @create="editor.createTemplate"
    />

    <MailTemplatePreviewModal
v-model="showPreview"
      :params="editor.previewParams.value"
      :loading="editor.previewLoading.value"
      :result="editor.previewResult.value"
      :param-keys="['user_name', 'email', 'site_name', 'verification_link', 'reset_link']"
      @update:param="(key, v) => (editor.previewParams.value[key] = v)"
      @generate="editor.runPreview"
    />
  </div>
</template>
