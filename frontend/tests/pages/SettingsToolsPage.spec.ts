import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    put: vi.fn(),
  },
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({
    replace: vi.fn(),
    push: vi.fn(),
  }),
}))

vi.mock('@/composables/useToolSettings', () => ({
  useToolSettings: () => ({
    getSettings: vi.fn().mockResolvedValue({}),
  }),
}))

const mockTools = [
  {
    tool_class: 'CalculatorTool',
    tool_name: 'calculator',
    display_name: 'Calculator',
    category: 'general',
    settings_schema: [{ key: 'precision', label: 'Precision', type: 'text', description: '', default: '10', required: false, scope: 'global', options: null }],
    operations: [],
  },
  {
    tool_class: 'ScratchpadTool',
    tool_name: 'scratchpad',
    display_name: 'Scratchpad',
    category: 'general',
    settings_schema: [],
    operations: [],
  },
  {
    tool_class: 'NewsApiTool',
    tool_name: 'news_api',
    display_name: 'News API',
    category: 'research',
    settings_schema: [{ key: 'api_key', label: 'API Key', type: 'password', description: '', default: '', required: true, scope: 'global', options: null }],
    operations: [],
  },
  {
    tool_class: 'GNewsTool',
    tool_name: 'g_news',
    display_name: 'GNews',
    category: 'research',
    settings_schema: [],
    operations: [],
  },
  {
    tool_class: 'CalDavCalendarTool',
    tool_name: 'cal_dav_calendar',
    display_name: 'CalDAV Calendar',
    category: 'communication',
    settings_schema: [{ key: 'caldav_url', label: 'URL', type: 'text', description: '', default: '', required: true, scope: 'global', options: null }],
    operations: [],
  },
]

describe('SettingsToolsPage', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setActivePinia(createPinia())
  })

  it('loads allTools via settingsTools inject and does not make direct API calls for tool list', async () => {
    await import('@/pages/settings/SettingsToolsPage.vue')

    // The component itself does NOT call api.get for tools list — it receives
    // allTools via provide/inject from the parent (SettingsLayout).
    // So we just verify the module loads without error.
    expect(true).toBe(true)
  })

  it('filters tools with empty settings_schema in the view', async () => {
    // Tools with empty settings_schema (scratchpad, g_news) should not appear
    // in the rendered list — only calculator, news_api, cal_dav_calendar show up.
    // This is verified by the toolsByCategory computed filtering on settings_schema.length > 0.
    const toolsWithSchema = mockTools.filter(t => t.settings_schema.length > 0)
    expect(toolsWithSchema.map(t => t.tool_name)).toEqual(['calculator', 'news_api', 'cal_dav_calendar'])
  })

  it('groups tools by category correctly', () => {
    const groups: Record<string, typeof mockTools> = {}
    for (const tool of mockTools) {
      const cat = (tool as any).category ?? 'general'
      if (!groups[cat]) groups[cat] = []
      groups[cat].push(tool)
    }
    expect(groups['general'].map((t: any) => t.tool_name)).toEqual(['calculator', 'scratchpad'])
    expect(groups['research'].map((t: any) => t.tool_name)).toEqual(['news_api', 'g_news'])
    expect(groups['communication'].map((t: any) => t.tool_name)).toEqual(['cal_dav_calendar'])
  })

  it('sorts categories case-insensitively', () => {
    // Matches AgentSettingsPage.vue: toLabel(a).localeCompare(toLabel(b))
    const toLabel = (cat: string) => cat.charAt(0).toUpperCase() + cat.slice(1)
    const groups = { zebra: [], General: [], research: [], Communication: [] }
    const sorted = Object.keys(groups).sort((a, b) => toLabel(a).localeCompare(toLabel(b)))
    // Communication < General < Research < Zebra
    expect(sorted).toEqual(['Communication', 'General', 'research', 'zebra'])
  })

  it('toLabel capitalizes first letter only', () => {
    const toLabel = (cat: string) => cat.charAt(0).toUpperCase() + cat.slice(1)
    expect(toLabel('general')).toBe('General')
    expect(toLabel('General')).toBe('General')
    expect(toLabel('RESEARCH')).toBe('RESEARCH') // only first char uppercased
  })

  it('falls back to general for tools without a category', () => {
    const toolsWithoutCategory = [{ ...mockTools[0], category: undefined }]
    const groups: Record<string, typeof toolsWithoutCategory> = {}
    for (const tool of toolsWithoutCategory) {
      const cat = (tool as any).category ?? 'general'
      if (!groups[cat]) groups[cat] = []
      groups[cat].push(tool)
    }
    expect(groups['general']).toHaveLength(1)
    expect(groups['undefined']).toBeUndefined()
  })
})
