/**
 * useConfirmDialog — global confirm dialog composable.
 *
 * Usage:
 *   const { confirm } = useConfirmDialog()
 *   const confirmed = await confirm('Delete this?')
 *
 * The dialog is auto-mounted to the app root on first use.
 */
import { ref, createApp, h } from 'vue'
import ConfirmDialogComponent from '@/components/ui/ConfirmDialog.vue'

const dialogRef = ref<InstanceType<typeof ConfirmDialogComponent> | null>(null)
let mounted = false

function ensureMounted(): void {
  if (mounted) return
  mounted = true

  const mount = document.createElement('div')
  document.body.appendChild(mount)

  const app = createApp({
    render: () => h(ConfirmDialogComponent, {
      ref: (el: unknown) => { dialogRef.value = el as typeof dialogRef.value }
    })
  })
  app.mount(mount)
}

// Hoisted to module scope: doesn't depend on per-call state (SonarQube typescript:S7721).
async function confirm(message: string, title = 'Confirm', confirmLabel = 'Delete'): Promise<boolean> {
  if (!dialogRef.value) return false
  return await dialogRef.value.open(message, title, confirmLabel) ?? false
}

export function useConfirmDialog() {
  ensureMounted()
  return { confirm }
}