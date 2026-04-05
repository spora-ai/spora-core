<script setup lang="ts">
import type { ToolSettingSchema } from '@/composables/useToolSettings'

defineProps<{
  modelValue: string | boolean | null
  field: ToolSettingSchema
  error?: string | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string | boolean | null]
}>()

function onInput(e: Event): void {
  const target = e.target as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement
  emit('update:modelValue', target.value)
}

const isPasswordMasked = (val: unknown): boolean => val === '***'
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <!-- Label -->
    <label :for="field.key" class="text-sm font-medium">
      {{ field.label }}
      <span v-if="field.required" class="text-destructive">*</span>
    </label>

    <!-- textarea -->
    <textarea
      v-if="field.type === 'textarea'"
      :id="field.key"
      :value="String(modelValue ?? '')"
      @input="onInput"
      :placeholder="field.description"
      :required="field.required"
      rows="3"
      class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
      :class="error ? 'border-destructive focus:ring-destructive' : ''"
    />

    <!-- select -->
    <select
      v-else-if="field.type === 'select'"
      :id="field.key"
      :value="String(modelValue ?? '')"
      @change="onInput"
      :required="field.required"
      class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
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

    <!-- toggle (custom checkbox) -->
    <label
      v-else-if="field.type === 'toggle'"
      class="relative inline-flex items-center cursor-pointer gap-3"
    >
      <button
        type="button"
        role="switch"
        :aria-checked="!!modelValue"
        @click="emit('update:modelValue', !modelValue)"
        class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-1 focus:ring-ring"
        :class="modelValue ? 'bg-primary' : 'bg-muted'"
      >
        <span
          class="inline-block h-4 w-4 rounded-full bg-white shadow-sm transition-transform"
          :class="modelValue ? 'translate-x-6' : 'translate-x-1'"
        />
      </button>
      <span v-if="field.description" class="text-xs text-muted-foreground">{{ field.description }}</span>
    </label>

    <!-- password -->
    <div v-else-if="field.type === 'password'" class="relative">
      <input
        :id="field.key"
        :value="isPasswordMasked(modelValue) ? '' : String(modelValue ?? '')"
        @input="onInput"
        :placeholder="isPasswordMasked(modelValue) ? '••••••••' : field.description"
        :required="field.required && !isPasswordMasked(modelValue)"
        type="password"
        autocomplete="new-password"
        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring pr-10"
        :class="error ? 'border-destructive focus:ring-destructive' : ''"
      />
    </div>

    <!-- text (default) -->
    <input
      v-else
      :id="field.key"
      :value="String(modelValue ?? '')"
      @input="onInput"
      type="text"
      :placeholder="field.description"
      :required="field.required"
      class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
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
