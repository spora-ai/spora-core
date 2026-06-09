/**
 * PluginDetailDialog — full metadata modal for a single plugin.
 *
 * The dialog uses <Teleport to="body">, so the rendered DOM lives in
 * document.body rather than inside the wrapper tree. Tests query the body
 * via a [data-testid] selector to keep assertions focused.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import PluginDetailDialog from '@/apps/plugins/components/PluginDetailDialog.vue'
import type { PluginResource } from '@/apps/plugins/types/plugin'

const basePlugin: PluginResource = {
  slug: 'minimax',
  name: 'MiniMax',
  description: 'Multimodal content generation.',
  version: 2,
  path: '/opt/spora-plugins/minimax',
  bundledTools: [
    { name: 'image', description: 'Generate an image from a prompt.' },
    { name: 'speech', description: 'Synthesize speech from text.' },
  ],
  bundledDrivers: [
    { provider: 'minimax', class: 'Spora\\Plugins\\MiniMax\\Driver' },
  ],
  recipePaths: ['/opt/spora-plugins/minimax/recipes'],
  migrations: {
    declared: 2,
    applied: 2,
    filesOnDisk: 2,
    pending: 0,
    lastAppliedAt: '2026-06-09 10:15:32',
    status: 'up_to_date',
  },
}

function dialogInBody(): HTMLElement | null {
  return document.body.querySelector('[data-testid="plugin-detail-dialog"]')
}

beforeEach(() => {
  // Each test mounts fresh; make sure no leftover teleported content lingers.
  document.body.querySelectorAll('[data-testid="plugin-detail-dialog"]').forEach(el => el.remove())
})

afterEach(() => {
  document.body.querySelectorAll('[data-testid="plugin-detail-dialog"]').forEach(el => el.remove())
})

describe('PluginDetailDialog', () => {
  it('renders nothing when open is false', () => {
    mount(PluginDetailDialog, {
      attachTo: document.body,
      props: { open: false, plugin: basePlugin },
    })
    expect(dialogInBody()).toBeNull()
  })

  it('renders nothing when plugin is null', () => {
    mount(PluginDetailDialog, {
      attachTo: document.body,
      props: { open: true, plugin: null },
    })
    expect(dialogInBody()).toBeNull()
  })

  it('renders the plugin header and description when open and plugin are set', () => {
    mount(PluginDetailDialog, {
      attachTo: document.body,
      props: { open: true, plugin: basePlugin },
    })
    const text = document.body.textContent ?? ''
    expect(text).toContain('MiniMax')
    expect(text).toContain('minimax')
    expect(text).toContain('Multimodal content generation.')
  })

  it('lists bundled tools with the slug-prefixed qualified name', () => {
    mount(PluginDetailDialog, {
      attachTo: document.body,
      props: { open: true, plugin: basePlugin },
    })
    const text = document.body.textContent ?? ''
    expect(text).toContain('minimax:image')
    expect(text).toContain('minimax:speech')
    expect(text).toContain('Generate an image from a prompt.')
  })

  it('lists bundled drivers', () => {
    mount(PluginDetailDialog, {
      attachTo: document.body,
      props: { open: true, plugin: basePlugin },
    })
    expect(document.body.textContent ?? '').toContain('Spora\\Plugins\\MiniMax\\Driver')
  })

  it('lists recipe paths', () => {
    mount(PluginDetailDialog, {
      attachTo: document.body,
      props: { open: true, plugin: basePlugin },
    })
    expect(document.body.textContent ?? '').toContain('/opt/spora-plugins/minimax/recipes')
  })

  it('shows the migration status badge and breakdown', () => {
    mount(PluginDetailDialog, {
      attachTo: document.body,
      props: { open: true, plugin: basePlugin },
    })
    const text = document.body.textContent ?? ''
    expect(text).toContain('Up to date')
    expect(text).toContain('Declared version')
    expect(text).toContain('Files on disk')
    expect(text).toContain('Applied')
    expect(text).toContain('Pending')
    expect(text).toContain('2026-06-09 10:15:32')
  })

  it('shows "never" for lastAppliedAt when null', () => {
    mount(PluginDetailDialog, {
      attachTo: document.body,
      props: {
        open: true,
        plugin: { ...basePlugin, migrations: { ...basePlugin.migrations, lastAppliedAt: null } },
      },
    })
    expect(document.body.textContent ?? '').toContain('never')
  })

  it('shows the plugin path', () => {
    mount(PluginDetailDialog, {
      attachTo: document.body,
      props: { open: true, plugin: basePlugin },
    })
    expect(document.body.textContent ?? '').toContain('/opt/spora-plugins/minimax')
  })

  it('hides the plugin path section when path is null', () => {
    mount(PluginDetailDialog, {
      attachTo: document.body,
      props: {
        open: true,
        // Clear both path and recipePaths so the "Plugin path" header is the only
        // thing the assertion is checking (recipe paths happen to share a prefix).
        plugin: { ...basePlugin, path: null, recipePaths: [] },
      },
    })
    expect(document.body.textContent ?? '').not.toContain('Plugin path')
  })

  it('emits close when the X button is clicked', async () => {
    const wrapper = mount(PluginDetailDialog, {
      attachTo: document.body,
      props: { open: true, plugin: basePlugin },
    })
    const closeButton = document.body.querySelector('button[aria-label="Close dialog"]') as HTMLElement | null
    expect(closeButton).not.toBeNull()
    closeButton!.click()
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('emits close when the backdrop is clicked', async () => {
    const wrapper = mount(PluginDetailDialog, {
      attachTo: document.body,
      props: { open: true, plugin: basePlugin },
    })
    const backdrop = document.body.querySelector('[data-testid="plugin-detail-dialog"]')?.parentElement as HTMLElement | null
    expect(backdrop).not.toBeNull()
    backdrop!.click()
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('omits the bundled tools section when no tools are declared', () => {
    mount(PluginDetailDialog, {
      attachTo: document.body,
      props: { open: true, plugin: { ...basePlugin, bundledTools: [] } },
    })
    expect(document.body.textContent ?? '').not.toContain('Bundled tools')
  })

  it('omits the bundled drivers section when no drivers are declared', () => {
    mount(PluginDetailDialog, {
      attachTo: document.body,
      props: { open: true, plugin: { ...basePlugin, bundledDrivers: [] } },
    })
    expect(document.body.textContent ?? '').not.toContain('Bundled drivers')
  })

  it('omits the recipe paths section when no recipes are declared', () => {
    mount(PluginDetailDialog, {
      attachTo: document.body,
      props: { open: true, plugin: { ...basePlugin, recipePaths: [] } },
    })
    expect(document.body.textContent ?? '').not.toContain('Recipe paths')
  })

  it('omits the description when plugin has none', () => {
    mount(PluginDetailDialog, {
      attachTo: document.body,
      props: { open: true, plugin: { ...basePlugin, description: '' } },
    })
    const text = document.body.textContent ?? ''
    // The description <p> is only rendered when description is truthy.
    expect(text).toContain('MiniMax')
    expect(text).not.toContain('Multimodal content generation.')
  })
})
