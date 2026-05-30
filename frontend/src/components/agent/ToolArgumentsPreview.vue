<script setup lang="ts">
import { computed, ref } from 'vue'
import hljs from 'highlight.js'
import { formatToolArguments, isFlatArguments, parseArguments } from '@/composables/useToolArgumentFormatter'

const props = defineProps<{
  arguments: Record<string, unknown> | string | null
  toolName?: string
  operation?: string | null
  expanded?: boolean
}>()

const showSensitive = ref<Record<string, boolean>>({})

// Parse arguments that may arrive as JSON string (handles double-escaping)
const parsedArgs = computed(() => parseArguments(props.arguments))

const flat = computed(() => isFlatArguments(parsedArgs.value))
const fields = computed(() => formatToolArguments(parsedArgs.value, { toolName: props.toolName, operation: props.operation }))

const highlightedJson = computed(() => {
  try {
    const json = JSON.stringify(parsedArgs.value, null, 2)
    const highlighted = hljs.highlight(json, { language: 'json' }).value
    return highlighted
  } catch {
    return JSON.stringify(parsedArgs.value, null, 2)
  }
})

function toggleSensitive(key: string) {
  showSensitive.value[key] = !showSensitive.value[key]
}

function isUrl(value: unknown): boolean {
  return typeof value === 'string' && /^https?:\/\//.test(value)
}

function isEmail(value: unknown): boolean {
  return typeof value === 'string' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
}

function copyToClipboard() {
  const json = JSON.stringify(props.arguments, null, 2)
  navigator.clipboard.writeText(json)
}

function formatValue(value: unknown, format: string): string {
  if (value === null || value === undefined) return '—'
  if (format === 'sensitive') {
    const str = String(value)
    if (str.length <= 4) return '••••••••'
    return str.slice(0, 2) + '••••••••' + str.slice(-2)
  }
  if (format === 'badge') return String(value).replace(/_/g, ' ')
  if (format === 'boolean') return value ? 'Yes' : 'No'
  return String(value)
}
</script>

<template>
  <!-- Flat arguments: read-only field list -->
  <div v-if="flat" class="rounded-lg border border-border bg-muted/20 overflow-hidden">
    <details class="group" :open="expanded ?? false">
      <summary class="flex items-center gap-1.5 px-3 py-2 cursor-pointer select-none list-none text-xs text-muted-foreground hover:bg-muted/30 transition-colors">
        <Icon name="chevron-right" class="h-3 w-3 shrink-0 transition-transform group-open:rotate-90" />
        Arguments ({{ fields.length }})
      </summary>

      <div class="px-3 py-2 border-t border-border space-y-2">
        <template v-for="field in fields" :key="field.key">
          <!-- Multiline body/message -->
          <div v-if="field.format === 'multiline'" class="flex flex-col gap-0.5">
            <span class="text-xs font-medium text-muted-foreground">{{ field.label }}</span>
            <pre class="text-xs font-mono text-foreground whitespace-pre-wrap break-words bg-muted/30 rounded px-2 py-1.5 max-h-40 overflow-y-auto">{{ String(field.value ?? '') }}</pre>
          </div>

          <!-- Email with mailto -->
          <div v-else-if="field.format === 'email'" class="flex items-center gap-2">
            <span class="text-xs font-medium text-muted-foreground min-w-[70px]">{{ field.label }}</span>
            <a
              v-if="isEmail(field.value)"
              :href="`mailto:${field.value}`"
              class="text-xs font-mono text-primary hover:underline"
            >
              {{ String(field.value) }}
            </a>
            <span v-else class="text-xs font-mono text-foreground">{{ String(field.value) }}</span>
          </div>

          <!-- URL as clickable link -->
          <div v-else-if="field.format === 'url'" class="flex items-center gap-2">
            <span class="text-xs font-medium text-muted-foreground min-w-[70px]">{{ field.label }}</span>
            <a
              v-if="isUrl(field.value)"
              :href="String(field.value)"
              target="_blank"
              rel="noopener"
              class="text-xs font-mono text-primary hover:underline truncate max-w-[250px]"
            >
              {{ String(field.value) }}
            </a>
            <span v-else class="text-xs font-mono text-foreground truncate max-w-[250px]">{{ String(field.value) }}</span>
          </div>

          <!-- Sensitive with toggle -->
          <div v-else-if="field.format === 'sensitive'" class="flex items-center gap-2">
            <span class="text-xs font-medium text-muted-foreground min-w-[70px]">{{ field.label }}</span>
            <span class="text-xs font-mono text-muted-foreground">
              {{ showSensitive[field.key] ? formatValue(field.value, field.format) : '••••••••' }}
            </span>
            <button
              type="button"
              @click="toggleSensitive(field.key)"
              class="text-xs text-muted-foreground hover:text-foreground"
            >
              {{ showSensitive[field.key] ? 'hide' : 'show' }}
            </button>
          </div>

          <!-- Badge (action, status, type) -->
          <div v-else-if="field.format === 'badge'" class="flex items-center gap-2">
            <span class="text-xs font-medium text-muted-foreground min-w-[70px]">{{ field.label }}</span>
            <span class="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary capitalize">
              {{ formatValue(field.value, field.format) }}
            </span>
          </div>

          <!-- Boolean toggle badge -->
          <div v-else-if="field.format === 'boolean'" class="flex items-center gap-2">
            <span class="text-xs font-medium text-muted-foreground min-w-[70px]">{{ field.label }}</span>
            <span :class="field.value ? 'text-green-600' : 'text-red-500'" class="text-xs font-medium">
              {{ formatValue(field.value, field.format) }}
            </span>
          </div>

          <!-- Default: single-line string -->
          <div v-else class="flex items-center gap-2">
            <span class="text-xs font-medium text-muted-foreground min-w-[70px]">{{ field.label }}</span>
            <span class="text-xs font-mono text-foreground truncate max-w-[250px]">{{ String(field.value ?? '') }}</span>
          </div>
        </template>
      </div>
    </details>
  </div>

  <!-- Nested arguments: read-only syntax-highlighted JSON -->
  <div v-else class="rounded-lg border border-border bg-muted/20 overflow-hidden">
    <details class="group" :open="expanded ?? false">
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
        <pre class="px-3 py-2 text-xs font-mono overflow-x-auto"><!-- eslint-disable-next-line vue/no-v-html --><code v-html="highlightedJson"></code></pre>
      </div>
    </details>
  </div>
</template>
