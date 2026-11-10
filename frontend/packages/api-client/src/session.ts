/**
 * Browser session shared by the admin panel and the timeclock app:
 * the JWT and the currently selected organization, persisted in
 * localStorage and observable for React (useSyncExternalStore).
 */
export interface SessionState {
  token: string | null
  organizationId: string | null
}

type Listener = () => void

export interface Session {
  get(): SessionState
  subscribe(listener: Listener): () => void
  signIn(token: string): void
  signOut(): void
  selectOrganization(organizationId: string | null): void
}

export function createSession(storageKey: string): Session {
  const listeners = new Set<Listener>()
  let state = read()

  function read(): SessionState {
    try {
      const raw = localStorage.getItem(storageKey)
      if (raw) return { token: null, organizationId: null, ...JSON.parse(raw) }
    } catch {
      // Storage unavailable or corrupt: start signed out.
    }
    return { token: null, organizationId: null }
  }

  function write(next: SessionState) {
    state = next
    try {
      localStorage.setItem(storageKey, JSON.stringify(next))
    } catch {
      // Keep the in-memory session even when storage is unavailable.
    }
    listeners.forEach((listener) => listener())
  }

  return {
    get: () => state,
    subscribe(listener) {
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
    signIn: (token) => write({ ...state, token }),
    signOut: () => write({ token: null, organizationId: null }),
    selectOrganization: (organizationId) => write({ ...state, organizationId }),
  }
}
