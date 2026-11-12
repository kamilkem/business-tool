import type { ProxyOptions } from "vite"

const COOKIE = "refresh_token"

/**
 * The dev server's /api proxy. In production each app has its own origin, so
 * each has its own refresh token cookie and session (ADR 0020). In dev both
 * apps run on localhost, where cookies ignore the port: the proxy stores this
 * app's cookie under its own name (`admin_refresh_token`) and hands it back to
 * the API under the API's name, so admin and timeclock keep separate sessions
 * (e.g. an owner in one, an employee in the other).
 */
export function apiProxy(app: string): ProxyOptions {
  const local = `${app}_${COOKIE}`

  return {
    target: process.env.API_URL ?? "http://localhost:8000",
    configure(proxy) {
      proxy.on("proxyReq", (proxyReq) => {
        const header = proxyReq.getHeader("cookie")
        if (typeof header !== "string") return
        const cookies = header
          .split(/;\s*/)
          .filter((cookie) => !cookie.startsWith(`${COOKIE}=`))
          .map((cookie) => (cookie.startsWith(`${local}=`) ? COOKIE + cookie.slice(local.length) : cookie))
        proxyReq.setHeader("cookie", cookies.join("; "))
      })
      proxy.on("proxyRes", (proxyRes) => {
        const setCookie = proxyRes.headers["set-cookie"]
        if (!setCookie) return
        proxyRes.headers["set-cookie"] = setCookie.map((cookie) =>
          cookie.startsWith(`${COOKIE}=`) ? local + cookie.slice(COOKIE.length) : cookie,
        )
      })
    },
  }
}
