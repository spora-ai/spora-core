<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import hljs from 'highlight.js'
import {
  formatToolArguments,
  isFlatArguments,
  parseArguments,
  type FormattedField,
} from '@/composables/useToolArgumentFormatter'

const props = defineProps<{
  arguments: Record<string, unknown> | string | null
  toolName?: string
  operation?: string | null
}>()

const emit = defineEmits<{
  'update:arguments': [value: string]
}>()

const showSensitive = ref<Record<string, boolean>>({})
const localFields = ref<FormattedField[]>([])

// Parse arguments that may arrive as JSON string (handles double-escaping)
const parsedArgs = computed(() => parseArguments(props.arguments))

// Check if arguments are flat (all primitives)
const flat = computed(() => isFlatArguments(parsedArgs.value))

// Initialize local fields when props change
watch(
  parsedArgs,
  (newArgs) => {
    localFields.value = formatToolArguments(newArgs, { toolName: props.toolName, operation: props.operation })
    showSensitive.value = {}
  },
  { immediate: true, deep: true }
)

// Emit updated arguments as JSON string
function emitUpdate() {
  const result: Record<string, unknown> = {}
  for (const field of localFields.value) {
    result[field.key] = field.value
  }
  emit('update:arguments', JSON.stringify(result))
}

function updateField(key: string, value: unknown) {
  const field = localFields.value.find(f => f.key === key)
  if (field) {
    field.value = value
    emitUpdate()
  }
}

function toggleSensitive(key: string) {
  showSensitive.value[key] = !showSensitive.value[key]
}

function isUrl(value: unknown): boolean {
  return typeof value === 'string' && /^https?:\/\//.test(value)
}

function isEmail(value: unknown): boolean {
  return typeof value === 'string' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
}

// Syntax-highlighted JSON for nested/non-flat args
const highlightedJson = computed(() => {
  if (flat.value) return ''
  try {
    const json = JSON.stringify(parsedArgs.value, null, 2)
    const language = 'json'
    const highlighted = hljs.highlight(json, { language }).value
    return highlighted
  } catch {
    return JSON.stringify(parsedArgs.value, null, 2)
  }
})

function copyToClipboard() {
  const json = JSON.stringify(parsedArgs.value, null, 2)
  navigator.clipboard.writeText(json)
}
</script>

<template>
  <!-- Flat arguments: editable form fields -->
  <div v-if="flat" class="flex flex-col gap-3">
    <div
      v-for="field in localFields"
      :key="field.key"
      class="flex flex-col gap-1"
    >
      <!-- Multiline textarea -->
      <template v-if="field.format === 'multiline'">
        <label class="text-xs font-medium text-muted-foreground">{{ field.label }}</label>
        <textarea
          :value="String(field.value ?? '')"
          @input="updateField(field.key, ($event.target as HTMLTextAreaElement).value)"
          rows="4"
          class="w-full resize-y rounded-lg border border-border bg-muted/30 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-1 focus:ring-ring"
          style="min-height: 80px; max-height: 200px"
        />
      </template>

      <!-- Email field -->
      <template v-else-if="field.format === 'email'">
        <label class="text-xs font-medium text-muted-foreground">{{ field.label }}</label>
        <div class="flex items-center gap-2">
          <input
            type="email"
            :value="String(field.value ?? '')"
            @input="updateField(field.key, ($event.target as HTMLInputElement).value)"
            class="flex-1 rounded-lg border border-border bg-muted/30 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-1 focus:ring-ring"
          />
          <a
            v-if="isEmail(field.value)"
            :href="`mailto:${field.value}`"
            target="_blank"
            rel="noopener"
            class="text-xs text-primary hover:underline shrink-0"
          >
            ↗ mailto
          </a>
        </div>
      </template>

      <!-- URL field -->
      <template v-else-if="field.format === 'url'">
        <label class="text-xs font-medium text-muted-foreground">{{ field.label }}</label>
        <div class="flex items-center gap-2">
          <input
            type="url"
            :value="String(field.value ?? '')"
            @input="updateField(field.key, ($event.target as HTMLInputElement).value)"
            class="flex-1 rounded-lg border border-border bg-muted/30 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-1 focus:ring-ring"
          />
          <a
            v-if="isUrl(field.value)"
            :href="String(field.value)"
            target="_blank"
            rel="noopener"
            class="text-xs text-primary hover:underline shrink-0"
          >
            ↗ open
          </a>
        </div>
      </template>

      <!-- Sensitive field -->
      <template v-else-if="field.format === 'sensitive'">
        <label class="text-xs font-medium text-muted-foreground">{{ field.label }}</label>
        <div class="flex items-center gap-2">
          <input
            :type="showSensitive[field.key] ? 'text' : 'password'"
            :value="String(field.value ?? '')"
            @input="updateField(field.key, ($event.target as HTMLInputElement).value)"
            class="flex-1 rounded-lg border border-border bg-muted/30 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-1 focus:ring-ring"
          />
          <button
            type="button"
            @click="toggleSensitive(field.key)"
            class="text-xs text-muted-foreground hover:text-foreground shrink-0 px-2"
          >
            {{ showSensitive[field.key] ? 'hide' : 'show' }}
          </button>
        </div>
      </template>

      <!-- Badge (action, status, type) - read only -->
      <template v-else-if="field.format === 'badge'">
        <label class="text-xs font-medium text-muted-foreground">{{ field.label }}</label>
        <span class="inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900/40 px-2.5 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300 w-fit capitalize">
          {{ String(field.value ?? '').replace(/_/g, ' ') }}
        </span>
      </template>

      <!-- Boolean toggle -->
      <template v-else-if="field.format === 'boolean'">
        <label class="text-xs font-medium text-muted-foreground">{{ field.label }}</label>
        <button
          type="button"
          @click="updateField(field.key, !field.value)"
          :class="[
            'relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2',
            field.value ? 'bg-green-600' : 'bg-red-500/50'
          ]"
        >
          <span
            :class="[
              'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
              field.value ? 'translate-x-6' : 'translate-x-1'
            ]"
          />
        </button>
      </template>

      <!-- Default: simple text input -->
      <template v-else>
        <label class="text-xs font-medium text-muted-foreground">{{ field.label }}</label>
        <input
          type="text"
          :value="String(field.value ?? '')"
          @input="updateField(field.key, ($event.target as HTMLInputElement).value)"
          class="w-full rounded-lg border border-border bg-muted/30 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-1 focus:ring-ring"
        />
      </template>
    </div>
  </div>

  <!-- Nested arguments: read-only syntax-highlighted JSON -->
  <div v-else class="rounded-lg border border-border bg-muted/20 overflow-hidden">
    <details class="group">
      <summary class="flex items-center gap-1.5 px-3 py-2 cursor-pointer select-none list-none text-xs text-muted-foreground hover:bg-muted/30 transition-colors">
        <Icon name="chevron-right" class="h-3 w-3 shrink-0 transition-transform group-open:rotate-90" />
        Arguments (complex structure)
      </summary>
      <div class="relative border-t border-border">
        <button
          type="button"
          @click="copyToClipboard"
          class="absolute right-2 top-2 text-xs text-muted-foreground hover:text-foreground inline-flex items-center gap-1 px-2 py-1 rounded bg-muted/50"
        >
          <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
          </svg>
          Copy
        </button>
        <pre class="px-3 py-2 text-xs font-mono overflow-x-auto"><code v-html="highlightedJson"></code></pre>
      </div>
    </details>
  </div>
</template>
