import { describe, it, expect } from 'vitest'
import {
  categoryLabel,
  groupToolsByCategory,
  sortCategoryKeys,
} from '@/utils/toolCategories'
import type { ToolSchema } from '@/composables/useToolSettings'

const makeTool = (overrides: Partial<ToolSchema> & { category?: string }): ToolSchema => ({
  tool_class: 'App\\Tools\\X',
  tool_name: 'x',
  display_name: 'X',
  settings_schema: [],
  operations: [],
  ...overrides,
  // Apply the default after the spread so the caller's category (if any) wins.
  category: overrides.category ?? 'general',
})

describe('categoryLabel', () => {
  it('capitalises the first character and leaves the rest as-is', () => {
    expect(categoryLabel('communication')).toBe('Communication')
    expect(categoryLabel('search')).toBe('Search')
  })

  it('handles a single-character category', () => {
    expect(categoryLabel('a')).toBe('A')
  })

  it('handles an empty string', () => {
    expect(categoryLabel('')).toBe('')
  })
})

describe('groupToolsByCategory', () => {
  it('groups tools by their category field', () => {
    const tools: ToolSchema[] = [
      makeTool({ tool_name: 'email', category: 'communication' }),
      makeTool({ tool_name: 'slack', category: 'communication' }),
      makeTool({ tool_name: 'serper_search', category: 'search' }),
    ]

    const groups = groupToolsByCategory(tools)

    expect(Object.keys(groups).sort()).toEqual(['communication', 'search'])
    expect(groups.communication).toHaveLength(2)
    expect(groups.search).toHaveLength(1)
  })

  it('defaults tools without a category to "general"', () => {
    const tools: ToolSchema[] = [
      makeTool({ tool_name: 'a' }), // no category override
      makeTool({ tool_name: 'b', category: 'search' }),
    ]

    const groups = groupToolsByCategory(tools)

    expect(groups.general).toHaveLength(1)
    expect(groups.general[0].tool_name).toBe('a')
    expect(groups.search).toHaveLength(1)
  })

  it('returns an empty object for empty input', () => {
    expect(groupToolsByCategory([])).toEqual({})
  })
})

describe('sortCategoryKeys', () => {
  it('sorts keys by their capitalised label alphabetically', () => {
    const sorted = sortCategoryKeys({
      search: [],
      communication: [],
      productivity: [],
    })
    expect(sorted).toEqual(['communication', 'productivity', 'search'])
  })

  it('handles a single key', () => {
    expect(sortCategoryKeys({ only: [] })).toEqual(['only'])
  })

  it('returns an empty array for an empty object', () => {
    expect(sortCategoryKeys({})).toEqual([])
  })
})
