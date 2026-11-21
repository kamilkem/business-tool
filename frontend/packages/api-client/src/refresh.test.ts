import { describe, expect, it, vi } from "vitest"

import { createApiClient, unwrap } from "./index"
import { createTokenRefresher, logout } from "./refresh"
import { createSession } from "./session"

const BASE = "http://api.test"

/** A fake API: answers by path, records every request with the Authorization it carried. */
function fakeApi(handlers: Record<string, (request: Request) => Response | Promise<Response>>) {
  const calls: {
    path: string
    authorization: string | null
    organization: string | null
    language: string | null
    body: string
  }[] = []
  const fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const request = new Request(input, init)
    const path = new URL(request.url).pathname
    calls.push({
      path,
      authorization: request.headers.get("Authorization"),
      organization: request.headers.get("X-Organization-Id"),
      language: request.headers.get("Accept-Language"),
      body: await request.clone().text(),
    })
    const handler = handlers[path]
    if (!handler) throw new Error(`Unexpected request to ${path}`)
    return handler(request)
  })
  return { fetch: fetch as unknown as typeof globalThis.fetch, calls, paths: () => calls.map((call) => call.path) }
}

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } })

/** /api/me answers 200 for the given JWT only. */
const meFor = (validToken: string) => (request: Request) =>
  request.headers.get("Authorization") === `Bearer ${validToken}` ? json({ email: "eve@example.com" }) : json({}, 401)

function signedInSession(token = "expired-jwt") {
  const session = createSession(`test-${Math.random()}`)
  session.signIn(token)
  session.selectOrganization("org-1")
  return session
}

describe("createTokenRefresher", () => {
  it("test_it_stores_the_new_token_in_the_session", async () => {
    const session = signedInSession()
    const api = fakeApi({ "/api/auth/refresh": () => json({ token: "fresh-jwt" }) })

    const outcome = await createTokenRefresher({ baseUrl: BASE, session, fetch: api.fetch })()

    expect(outcome).toEqual({ status: "refreshed", token: "fresh-jwt" })
    expect(session.get()).toEqual({ token: "fresh-jwt", organizationId: "org-1" })
  })

  it("test_it_shares_one_refresh_between_concurrent_callers", async () => {
    const session = signedInSession()
    let answer: (response: Response) => void = () => {}
    const api = fakeApi({ "/api/auth/refresh": () => new Promise<Response>((resolve) => (answer = resolve)) })
    const refresh = createTokenRefresher({ baseUrl: BASE, session, fetch: api.fetch })

    const both = Promise.all([refresh(), refresh()])
    await vi.waitFor(() => expect(api.calls).toHaveLength(1))
    answer(json({ token: "fresh-jwt" }))

    expect(await both).toEqual([
      { status: "refreshed", token: "fresh-jwt" },
      { status: "refreshed", token: "fresh-jwt" },
    ])
    expect(api.paths()).toEqual(["/api/auth/refresh"])
  })

  it("test_it_refreshes_again_after_the_previous_refresh_finished", async () => {
    const session = signedInSession()
    let issued = 0
    const api = fakeApi({ "/api/auth/refresh": () => json({ token: `jwt-${++issued}` }) })
    const refresh = createTokenRefresher({ baseUrl: BASE, session, fetch: api.fetch })

    await refresh()
    const second = await refresh()

    expect(second).toEqual({ status: "refreshed", token: "jwt-2" })
  })

  it("test_it_reports_a_refused_cookie_as_rejected", async () => {
    const session = signedInSession()
    const api = fakeApi({ "/api/auth/refresh": () => json({ code: 401 }, 401) })

    expect(await createTokenRefresher({ baseUrl: BASE, session, fetch: api.fetch })()).toEqual({ status: "rejected" })
    expect(session.get().token).toBe("expired-jwt")
  })

  it("test_it_reports_network_and_server_errors_as_unavailable", async () => {
    const session = signedInSession()
    const offline = vi.fn(async () => {
      throw new TypeError("Failed to fetch")
    }) as unknown as typeof globalThis.fetch
    const failing = fakeApi({ "/api/auth/refresh": () => json({}, 502) })

    expect(await createTokenRefresher({ baseUrl: BASE, session, fetch: offline })()).toEqual({ status: "unavailable" })
    expect(await createTokenRefresher({ baseUrl: BASE, session, fetch: failing.fetch })()).toEqual({
      status: "unavailable",
    })
    expect(session.get().token).toBe("expired-jwt")
  })
})

describe("createApiClient", () => {
  it("test_it_refreshes_an_expired_token_and_retries_the_request", async () => {
    const session = signedInSession()
    const onUnauthorized = vi.fn()
    const api = fakeApi({
      "/api/me": meFor("fresh-jwt"),
      "/api/auth/refresh": () => json({ token: "fresh-jwt" }),
    })
    const client = createApiClient({ baseUrl: BASE, session, onUnauthorized, fetch: api.fetch })

    const me = await unwrap(client.GET("/api/me"))

    expect(me).toEqual({ email: "eve@example.com" })
    expect(api.paths()).toEqual(["/api/me", "/api/auth/refresh", "/api/me"])
    expect(api.calls[1]?.authorization).toBeNull()
    expect(api.calls[2]?.authorization).toBe("Bearer fresh-jwt")
    expect(onUnauthorized).not.toHaveBeenCalled()
  })

  it("test_it_retries_a_request_for_the_same_organization", async () => {
    const session = signedInSession()
    const api = fakeApi({
      "/api/shifts": (request) =>
        request.headers.get("Authorization") === "Bearer fresh-jwt" ? json([]) : json({}, 401),
      "/api/auth/refresh": () => json({ token: "fresh-jwt" }),
    })
    const client = createApiClient({ baseUrl: BASE, session, fetch: api.fetch })

    await unwrap(client.GET("/api/shifts", { params: { query: { from: "2026-11-01", to: "2026-11-30" } } }))

    expect(api.calls.map((call) => [call.path, call.organization])).toEqual([
      ["/api/shifts", "org-1"],
      ["/api/auth/refresh", null],
      ["/api/shifts", "org-1"],
    ])
  })

  it("test_it_retries_a_request_with_its_body", async () => {
    const session = signedInSession()
    const api = fakeApi({
      "/api/organizations": (request) =>
        request.headers.get("Authorization") === "Bearer fresh-jwt" ? json({ id: "org-2" }, 201) : json({}, 401),
      "/api/auth/refresh": () => json({ token: "fresh-jwt" }),
    })
    const client = createApiClient({ baseUrl: BASE, session, fetch: api.fetch })

    const { response } = await client.POST("/api/organizations", { body: { name: "Night Bar" } })

    expect(response.status).toBe(201)
    expect(api.calls.map((call) => call.body)).toEqual(['{"name":"Night Bar"}', "", '{"name":"Night Bar"}'])
  })

  it("test_it_shares_one_refresh_between_concurrent_401s", async () => {
    const session = signedInSession()
    let answer: (response: Response) => void = () => {}
    const api = fakeApi({
      "/api/me": meFor("fresh-jwt"),
      "/api/organizations": (request) =>
        request.headers.get("Authorization") === "Bearer fresh-jwt" ? json([]) : json({}, 401),
      "/api/auth/refresh": () => new Promise<Response>((resolve) => (answer = resolve)),
    })
    const client = createApiClient({ baseUrl: BASE, session, fetch: api.fetch })

    const both = Promise.all([unwrap(client.GET("/api/me")), unwrap(client.GET("/api/organizations"))])
    await vi.waitFor(() => expect(api.paths()).toContain("/api/auth/refresh"))
    answer(json({ token: "fresh-jwt" }))
    await both

    expect(api.paths().filter((path) => path === "/api/auth/refresh")).toHaveLength(1)
    expect(api.calls.filter((call) => call.authorization === "Bearer fresh-jwt")).toHaveLength(2)
  })

  it("test_it_retries_with_a_token_refreshed_meanwhile_without_refreshing_again", async () => {
    const session = signedInSession()
    const api = fakeApi({
      "/api/me": (request) => {
        // The token was refreshed (another tab or request) while this one was on its way.
        session.signIn("fresh-jwt")
        return meFor("fresh-jwt")(request)
      },
      "/api/auth/refresh": () => json({ token: "never" }),
    })
    const client = createApiClient({ baseUrl: BASE, session, fetch: api.fetch })

    await unwrap(client.GET("/api/me"))

    expect(api.paths()).toEqual(["/api/me", "/api/me"])
  })

  it("test_it_signs_out_when_the_refresh_is_refused", async () => {
    const session = signedInSession()
    const onUnauthorized = vi.fn()
    const api = fakeApi({
      "/api/me": meFor("fresh-jwt"),
      "/api/auth/refresh": () => json({ code: 401 }, 401),
    })
    const client = createApiClient({ baseUrl: BASE, session, onUnauthorized, fetch: api.fetch })

    await expect(unwrap(client.GET("/api/me"))).rejects.toMatchObject({ status: 401 })

    expect(session.get()).toEqual({ token: null, organizationId: null })
    expect(onUnauthorized).toHaveBeenCalledOnce()
    expect(api.paths()).toEqual(["/api/me", "/api/auth/refresh"])
  })

  it("test_it_signs_out_when_the_retried_request_is_still_unauthorized", async () => {
    const session = signedInSession()
    const onUnauthorized = vi.fn()
    const api = fakeApi({
      "/api/me": () => json({}, 401),
      "/api/auth/refresh": () => json({ token: "fresh-jwt" }),
    })
    const client = createApiClient({ baseUrl: BASE, session, onUnauthorized, fetch: api.fetch })

    await expect(unwrap(client.GET("/api/me"))).rejects.toMatchObject({ status: 401 })

    expect(api.paths()).toEqual(["/api/me", "/api/auth/refresh", "/api/me"])
    expect(session.get().token).toBeNull()
    expect(onUnauthorized).toHaveBeenCalledOnce()
  })

  it("test_it_keeps_the_session_when_the_refresh_cannot_reach_the_api", async () => {
    const session = signedInSession()
    const onUnauthorized = vi.fn()
    const api = fakeApi({
      "/api/me": () => json({}, 401),
      "/api/auth/refresh": () => json({}, 503),
    })
    const client = createApiClient({ baseUrl: BASE, session, onUnauthorized, fetch: api.fetch })

    await expect(unwrap(client.GET("/api/me"))).rejects.toMatchObject({ status: 401 })

    expect(session.get().token).toBe("expired-jwt")
    expect(onUnauthorized).not.toHaveBeenCalled()
  })

  it("test_it_sends_the_language_shown_now_on_every_request_and_its_retry", async () => {
    const session = signedInSession()
    let language = "pl"
    const api = fakeApi({
      "/api/me": meFor("fresh-jwt"),
      "/api/auth/refresh": () => json({ token: "fresh-jwt" }),
      "/api/auth/password-reset": () => new Response(null, { status: 202 }),
    })
    const client = createApiClient({ baseUrl: BASE, session, language: () => language, fetch: api.fetch })

    await unwrap(client.GET("/api/me"))
    language = "en"
    await client.POST("/api/auth/password-reset", { body: { email: "eve@example.com", app: "admin" } })

    expect(api.calls.map((call) => [call.path, call.language])).toEqual([
      ["/api/me", "pl"],
      ["/api/auth/refresh", null],
      ["/api/me", "pl"],
      ["/api/auth/password-reset", "en"],
    ])
  })

  it("test_it_sends_no_language_when_the_app_gives_none", async () => {
    const api = fakeApi({ "/api/me": meFor("expired-jwt") })
    const client = createApiClient({ baseUrl: BASE, session: signedInSession(), fetch: api.fetch })

    await unwrap(client.GET("/api/me"))

    expect(api.calls[0]?.language).toBeNull()
  })

  it("test_it_never_refreshes_without_a_token", async () => {
    const session = createSession(`test-${Math.random()}`)
    const onUnauthorized = vi.fn()
    const api = fakeApi({ "/api/me": () => json({}, 401) })
    const client = createApiClient({ baseUrl: BASE, session, onUnauthorized, fetch: api.fetch })

    await expect(unwrap(client.GET("/api/me"))).rejects.toMatchObject({ status: 401 })

    expect(api.paths()).toEqual(["/api/me"])
    expect(onUnauthorized).not.toHaveBeenCalled()
  })
})

describe("logout", () => {
  it("test_it_revokes_the_cookie_session_and_clears_the_local_one", async () => {
    const session = signedInSession("jwt")
    const api = fakeApi({ "/api/auth/logout": () => new Response(null, { status: 204 }) })

    await logout(session, BASE, api.fetch)

    expect(api.paths()).toEqual(["/api/auth/logout"])
    expect(session.get()).toEqual({ token: null, organizationId: null })
  })

  it("test_it_clears_the_local_session_even_offline", async () => {
    const session = signedInSession("jwt")
    const offline = vi.fn(async () => {
      throw new TypeError("Failed to fetch")
    }) as unknown as typeof globalThis.fetch

    await logout(session, BASE, offline)

    expect(session.get().token).toBeNull()
  })
})
