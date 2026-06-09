<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { api } from '@/api/client'
import Toggle from '@/components/ui/Toggle.vue'
import Icon from '@/components/ui/Icon.vue'
import type { ToolSettingSchema } from '@/composables/useToolSettings'

const props = defineProps<{
  modelValue: string | boolean | number[] | null
  field: ToolSettingSchema
  error?: string | null
  disabled?: boolean
  hideLabel?: boolean
  customPlaceholder?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string | boolean | number[] | null]
}>()

const isPasswordMasked = (val: unknown): boolean => val === '***'

// When a password field has a saved value, we show a locked "••••••••" display
// instead of a blank input. The user must explicitly click "Change" to edit it.
const editingPassword = ref(false)

// If the parent reloads a masked value (e.g. user cancels and reopens the modal),
// exit edit mode so the locked display is shown again.
watch(
  () => props.modelValue,
  (val) => { if (isPasswordMasked(val)) editingPassword.value = false },
)

function startPasswordEdit(): void {
  editingPassword.value = true
  emit('update:modelValue', '')
}

function cancelPasswordEdit(): void {
  editingPassword.value = false
  emit('update:modelValue', '***')
}

function onInput(e: Event): void {
  const target = e.target as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement
  emit('update:modelValue', target.value)
}

function resolveOptionLabel(options: Record<string, string> | string[] | null | undefined, value: unknown): string {
  if (!options || value == null) return String(value ?? '')
  if (Array.isArray(options)) return String(value)
  return (options as Record<string, string>)[String(value)] ?? String(value)
}

const multiSelectOptions = ref<Array<{ id: number; name: string }>>([])
const multiSelectLoading = ref(false)
const multiSelectEndpoint = computed(() =>
  props.field.multi_select_options_endpoint ?? '/agents?select=id,name',
)
// The parent form is a Record<string, string>, so multi-select values are
// transported as JSON-encoded strings (e.g. "[1,5,7]"). Parse them here so
// the checkbox state stays in sync regardless of whether the value arrived
// as an array, a JSON string, or a legacy raw value.
const multiSelectSelected = computed<number[]>(() => {
  const v = props.modelValue
  if (Array.isArray(v)) return v.map(Number)
  if (typeof v === 'string' && v !== '') {
    try {
      const parsed = JSON.parse(v)
      if (Array.isArray(parsed)) return parsed.map(Number)
    } catch { /* fall through */ }
  }
  return []
})

onMounted(async () => {
  if (props.field.type !== 'multi-select') return
  multiSelectLoading.value = true
  try {
    // api.get already unwraps body.data (see api/client.ts), so the shape is { agents: [...] }.
    const res = await api.get<{ agents: Array<{ id: number; name: string }> }>(multiSelectEndpoint.value)
    multiSelectOptions.value = res.agents ?? []
  } finally {
    multiSelectLoading.value = false
  }
})

function toggleMultiSelect(id: number, checked: boolean): void {
  const next = checked
    ? [...multiSelectSelected.value, id]
    : multiSelectSelected.value.filter(x => x !== id)
  // Emit a JSON string so the parent's `String($event ?? '')` is a no-op and
  // the form keeps a Record<string, string> shape without losing the array.
  emit('update:modelValue', JSON.stringify(next))
}
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <!-- Label (suppressed when the parent renders its own header row) -->
    <label v-if="!hideLabel" :for="field.key" class="text-sm font-medium flex items-center gap-1.5">
      {{ field.label }}
      <span v-if="field.required" class="text-destructive">*</span>
      <span
        v-if="field.expose_to_llm"
        title="This setting is visible to the LLM and influences its behavior"
        class="text-primary/60"
      >
        <Icon name="sparkles" class="h-3.5 w-3.5" />
      </span>
    </label>

    <!-- textarea -->
    <textarea
      v-if="field.type === 'textarea'"
      :id="field.key"
      :value="String(modelValue ?? '')"
      @input="onInput"
      :placeholder="customPlaceholder ?? (field.default != null ? String(field.default) : field.description)"
      :required="field.required"
      :disabled="disabled"
      rows="3"
      autocomplete="off"
      class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50 disabled:cursor-not-allowed"
      :class="error ? 'border-destructive focus:ring-destructive' : ''"
    />

    <!-- select -->
    <select
      v-else-if="field.type === 'select'"
      :id="field.key"
      :value="String(modelValue ?? '')"
      @change="onInput"
      :required="field.required"
      :disabled="disabled"
      class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50 disabled:cursor-not-allowed"
      :class="error ? 'border-destructive focus:ring-destructive' : ''"
    >
      <option v-if="!field.required" value="">
        —{{ (field.default != null && field.default !== '') ? ` (Default: ${resolveOptionLabel(field.options, field.default)})` : '' }}
      </option>
      <!-- Handle Array options -->
      <template v-if="Array.isArray(field.options)">
        <option v-for="opt in field.options" :key="opt" :value="opt">
          {{ opt }}
        </option>
      </template>
      <!-- Handle Object options -->
      <template v-else>
        <option v-for="(label, value) in field.options || {}" :key="String(value)" :value="String(value)">
          {{ label }}
        </option>
      </template>
    </select>

    <!-- toggle -->
    <label
      v-else-if="field.type === 'toggle'"
      :for="field.key"
      class="relative inline-flex items-center cursor-pointer gap-3"
      :class="disabled ? 'opacity-50 cursor-not-allowed' : ''"
    >
      <Toggle
        :id="field.key"
        :aria-label="field.label"
        :model-value="!!modelValue"
        :disabled="disabled"
        @update:model-value="!disabled && emit('update:modelValue', !modelValue)"
      />
      <span v-if="field.description" class="text-xs text-muted-foreground">{{ field.description }}</span>
    </label>

    <!-- multi-select -->
    <div v-else-if="field.type === 'multi-select'" class="flex flex-col gap-1.5">
      <div v-if="multiSelectLoading" class="text-sm text-muted-foreground">Loading options…</div>
      <div v-else-if="multiSelectOptions.length === 0" class="text-sm text-muted-foreground">No options available.</div>
      <label
        v-for="opt in multiSelectOptions"
        v-else
        :key="opt.id"
        class="flex items-center gap-2 text-sm"
      >
        <input
          type="checkbox"
          :value="opt.id"
          :checked="multiSelectSelected.includes(opt.id)"
          @change="toggleMultiSelect(opt.id, ($event.target as HTMLInputElement).checked)"
        />
        <span>{{ opt.name }} <span class="text-slate-400">#{{ opt.id }}</span></span>
      </label>
    </div>

    <!-- password -->
    <div v-else-if="field.type === 'password'">
      <!-- Value already set: locked display + Change button -->
      <div
        v-if="isPasswordMasked(modelValue) && !editingPassword"
        class="flex items-center gap-2"
      >
        <div
          class="flex-1 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm font-mono tracking-[0.3em] text-muted-foreground select-none"
          :class="disabled ? 'opacity-50' : ''"
        >
          ••••••••
        </div>
        <button
          v-if="!disabled"
          type="button"
          @click="startPasswordEdit"
          class="shrink-0 text-xs text-primary hover:text-primary/80 transition-colors"
        >
          Change
        </button>
      </div>
      <!-- Editing: empty input for new value + Cancel link -->
      <div v-else class="relative">
        <input
          :id="field.key"
          :value="String(modelValue ?? '')"
          @input="onInput"
          :placeholder="customPlaceholder ?? (field.default != null ? String(field.default) : field.description)"
          :required="field.required"
          :disabled="disabled"
          type="password"
          autocomplete="off"
          class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50 disabled:cursor-not-allowed"
          :class="[error ? 'border-destructive focus:ring-destructive' : '', editingPassword ? 'pr-16' : '']"
        />
        <button
          v-if="editingPassword"
          type="button"
          @click="cancelPasswordEdit"
          class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-muted-foreground hover:text-foreground transition-colors"
        >
          Cancel
        </button>
      </div>
    </div>

    <!-- text (default) -->
    <input
      v-else
      :id="field.key"
      :value="String(modelValue ?? '')"
      @input="onInput"
      type="text"
      :placeholder="field.default != null ? String(field.default) : field.description"
      :required="field.required"
      :disabled="disabled"
      autocomplete="off"
      class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50 disabled:cursor-not-allowed"
      :class="error ? 'border-destructive focus:ring-destructive' : ''"
    />

    <!-- Description -->
    <p v-if="field.description && field.type !== 'toggle'" class="text-xs text-muted-foreground">
      {{ field.description }}
    </p>

    <!-- Error -->
    <p v-if="error" role="alert" class="text-xs text-destructive">{{ error }}</p>
  </div>
</template>
