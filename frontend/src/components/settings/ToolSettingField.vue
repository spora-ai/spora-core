<script setup lang="ts">
import { ref, watch } from 'vue'
import Toggle from '@/components/ui/Toggle.vue'
import type { ToolSettingSchema } from '@/composables/useToolSettings'

const props = defineProps<{
  modelValue: string | boolean | null
  field: ToolSettingSchema
  error?: string | null
  disabled?: boolean
  hideLabel?: boolean
  customPlaceholder?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string | boolean | null]
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
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <!-- Label (suppressed when the parent renders its own header row) -->
    <label v-if="!hideLabel" :for="field.key" class="text-sm font-medium">
      {{ field.label }}
      <span v-if="field.required" class="text-destructive">*</span>
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
      <option v-if="!field.required" value="">—</option>
      <option
        v-for="opt in field.options ?? []"
        :key="opt"
        :value="opt"
      >
        {{ opt }}
      </option>
    </select>

    <!-- toggle -->
    <label
      v-else-if="field.type === 'toggle'"
      class="relative inline-flex items-center cursor-pointer gap-3"
      :class="disabled ? 'opacity-50 cursor-not-allowed' : ''"
    >
      <Toggle
        :model-value="!!modelValue"
        :disabled="disabled"
        @update:model-value="!disabled && emit('update:modelValue', !modelValue)"
      />
      <span v-if="field.description" class="text-xs text-muted-foreground">{{ field.description }}</span>
    </label>

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
