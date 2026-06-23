import { router } from '@/router'
import type { NavigationFailure } from 'vue-router'

export function navigateTo(to: string | { path: string; query?: Record<string, string> }, options?: { replace?: boolean }): Promise<void | NavigationFailure | undefined> {
  return options?.replace ? router.replace(to as string) : router.push(to as string)
}
