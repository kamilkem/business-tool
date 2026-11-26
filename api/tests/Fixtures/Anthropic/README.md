# Claude API fixtures

Responses of `POST /v1/messages` for the item interpreter's adapter test
(`tests/Unit/Purchasing/Infrastructure/AnthropicItemInterpreterTest.php`, adr/0042). They are written to
the documented response shape (a `message` with `content` blocks, `stop_reason`, `usage`; errors as
`{"type": "error", "error": {...}}`), **not recorded from a live call**: the ids and the names in them are
synthetic. When a key is available, record a real answer for synthetic item names and compare its shape
with these before trusting them more. Tests and CI never reach the network (CLAUDE.md).
