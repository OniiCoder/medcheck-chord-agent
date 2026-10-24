# Deployment Field Notes — MedCheck Diagnostics Chord Agent

**Candidate Role:** Forward-Deployed Engineer

Below are the specific technical issues, UI gaps, deployment hurdles, and API contract edge cases encountered while building and deploying the MedCheck Diagnostics agent on Chord, grouped into three sections:
1. **Section 1 — Chord Web UI, Onboarding & Exercise Brief Field Notes**
2. **Section 2 — Paystack Test Mode & Laravel Forge Production Deployment Field Notes**
3. **Section 3 — Chord REST API & `php artisan chord:deploy` (`ChordDeployCommand`) Field Notes**

---

## Section 1: Chord Web UI, Onboarding & Exercise Brief

### Field Note 1: Account Signup 6-Digit Verification OTP Routed to Spam

* **What happened:**
  - During Chord account signup (`chordhq.xyz/login`), the 6-digit email verification code did not arrive in the primary inbox and was routed directly to the Spam folder.
* **How I worked out the cause:**
  - Checked the Spam folder after waiting in the primary inbox and found the verification email flagged by the mail provider's spam filter (typical when a transactional sender domain/IP lacks warmed SPF, DKIM, and DMARC alignment).
* **How I fixed it / worked around it:**
  - Retrieved the 6-digit code from Spam and marked the sender as "Not Spam" so subsequent Chord notifications land in the inbox.
* **What I'd change in Chord next week:**
  - Audit SPF/DKIM/DMARC records and transactional IP reputation for the OTP sender domain, and add a short hint on the OTP verification screen (*"Didn't get the code? Check your Spam/Promotions folder"*).

---

### Field Note 2: No Connected Tool Count or Status Indicator Under `Connections > Channels & Tools` on the Admin Page

* **What happened:**
  - After registering and connecting our custom MCP tool server, the Admin page (`Connections > Channels & Tools`) showed no visual badge, count, or status indicator confirming that a custom tool was configured and active.
* **How I worked out the cause:**
  - Clicked back into the MCP / Tools configuration view and tested the teammate in the simulator to verify the tool was bound, confirming that the parent `Connections > Channels & Tools` list item doesn't render summary state for connected custom MCP servers.
* **How I fixed it / worked around it:**
  - Verified active tool registration by opening the tool configuration sub-view directly and monitoring inbound `tools/list` and `tools/call` requests on our Laravel Forge Operations Console.
* **What I'd change in Chord next week:**
  - Display a status pill or counter on the `Connections > Channels & Tools` row (e.g., `1 MCP Server Connected · 4 Tools Active` with a green health dot) so builders can see at a glance from the Admin page that their custom tools are wired up.

---

### Field Note 3: Broken Context Link in Exercise Brief & SSR `403` on `tsglabs.com`

* **What happened (including error messages):**
  - The exercise brief's context link (`https://tsglabs.com/blog/announcing-tsg-labs`) returns `HTTP 404 Not Found`, and `https://tsglabs.com/` contains a Nuxt SSR error in `__NUXT_DATA__`: `{"statusCode": 403, "message": "[GET] \"/api/blog/posts\": 403 Forbidden"}`.
* **How I worked out the cause:**
  - Inspected the serialized `__NUXT_DATA__` payload on `tsglabs.com`, showing the server-side fetch to `/api/blog/posts` failing with `403 Forbidden`.
* **How I fixed it / worked around it:**
  - Used `https://api.chordhq.xyz/docs` and the `paystack-index` interactive terminal on `tsglabs.com` for platform context.
* **What I'd change next week:**
  - Restore the public read token/permissions backing `GET /api/blog/posts` on `tsglabs.com`.

---

## Section 2: Paystack Test Mode & Laravel Forge Production Deployment

### Field Note 4: Paystack Test Mode `400 Currency not supported by merchant` vs. `disabled_merchant`

* **What happened (including error messages):**
  1. Sending hardcoded `'currency' => 'NGN'` to `POST https://api.paystack.co/transaction/initialize` with our first test key failed with HTTP `400`: `{"status": false, "message": "Currency not supported by merchant"}`.
  2. Testing a second older Nigerian test key (`sk_test_4104...`) returned `{"status": false, "message": "Integration has been deactivated", "code": "disabled_merchant"}`.
* **How I worked out the cause:**
  - Probed `/transaction/initialize` omitting `currency` and inspected `/transaction/verify/{ref}`, revealing the first test merchant account was domiciled in Ghana (`"currency": "GHS"`), while the second merchant account was deactivated on Paystack's side.
* **How I fixed it / worked around it:**
  - Updated `PaystackService::initializeTransaction()` so `currency` is configurable via `PAYSTACK_CURRENCY` and automatically retries without `currency` if Paystack rejects the currency code, and updated `verify_payment_and_issue_pass` to mint a fresh Paystack reference (`PSK-...`) and new `checkout_url` whenever a payment attempt fails.
* **What I'd change in Chord / Exercise Starter Docs next week:**
  - Add a note in the exercise brief reminding builders not to hardcode `currency: "NGN"` on Paystack `/transaction/initialize` when using non-NGN regional test accounts.

---

### Field Note 5: Agent Repeatedly Retrying `check_clinic_slots` on Fresh Laravel Forge Deployment Due to Unseeded Database

* **What happened (including error messages):**
  - After deploying the repository to Laravel Forge and pointing Chord to the Forge `/mcp` URL, asking Ada for available slots caused her to reply: *"The test code matches the catalog, so I'll retry the slot check once more."*
* **How I worked out the cause:**
  - Laravel Forge's default deploy script runs `php artisan migrate --force` (creating empty `diagnostic_tests` and `clinic_slots` tables) but does **not** run `php artisan db:seed --force`. When Ada called `check_clinic_slots(test_code="LIPID-FAST")`, the database had `0` rows in `diagnostic_tests` and returned `INVALID_TEST_CODE`, which contradicted `references/test_catalog_and_prep.md` in her skill and caused the LLM to retry.
* **How I fixed it / worked around it:**
  - Added an automatic self-seeding guard in `McpServerController::executeTool()` and `DashboardController::index()` (`if (DiagnosticTest::count() === 0) { app(DatabaseSeeder::class)->run(); }`) and added resilient test-code alias resolution (`resolveDiagnosticTest()`).
* **What I'd change in Chord next week:**
  - In the WhatsApp Simulator UI, add a collapsible **"Tool Call Inspector" (Request / Response drawer)** for builders so when an MCP tool returns an application-level error (`isError: true`, e.g., `INVALID_TEST_CODE` from an unseeded DB), the builder can see the raw tool output right inside the simulator chat without having to tail server logs.

---

## Section 3: Chord REST API & `ChordDeployCommand` (`php artisan chord:deploy`)

These four issues were uncovered while building and running [`ChordDeployCommand.php`](app/Console/Commands/ChordDeployCommand.php) to automate teammate discovery, skill draft creation/publishing, MCP server registration, teammate tool scoping, and `/health` + `/tools` probing via `https://api.chordhq.xyz`.

### Field Note 6: MCP `streamable_http` Registration Succeeds (`201 Created`) While Subsequent `/tools` & `/health` Probes Fail Due to CSRF, Static-Auth State, and Notification Status Codes

* **What happened (including error messages):**
  1. Registering our Laravel MCP endpoint (`/mcp`) via `POST /v1/workspaces/{ws}/mcp-servers` returned `201 Created` (`{"id": "mcp-...", "enabled": false, "transport": "streamable_http"}`).
  2. Probing `POST /v1/workspaces/{ws}/mcp-servers/{id}/health` right after registration while `enabled: false` returned HTTP `200 OK` from Chord containing `{"status": "error", "error": "..."}` or `502 BAD_GATEWAY` on `/tools`.
  3. On Laravel 11–13, routes in `routes/web.php` enforce `VerifyCsrfToken` (`419 Page Expired`) by default, and MCP `notifications/initialized` messages (which omit the JSON-RPC `id` field) cause naive JSON-RPC handlers to return an invalid JSON-RPC response with `"id": null` instead of `202 Accepted`.
* **How I worked out the cause:**
  - Logged every inbound request to `/mcp` in `mcp_audit_logs` and cross-checked `/docs/mcp-servers/#3-discover-tools-and-check-health`:
    1. **Probe credential gating:** A disabled server (`enabled: false`) does not send its stored bearer/API-key credential during `/health` or `/tools` probes, causing a `401` on the remote MCP server if probed before enabling.
    2. **HTTP 200 wrapper on `/health`:** `POST .../health` returns HTTP `200` even when the MCP handshake fails, embedding `"status": "error"` inside the JSON body.
* **How I fixed it / worked around it:**
  - Exempted `mcp` and `mcp/*` from CSRF in `bootstrap/app.php` and returned HTTP `202 Accepted` on JSON-RPC notifications without an `id` in `McpServerController.php`.
  - Ordered `ChordDeployCommand` to: (1) register disabled, (2) scope `set_mcp_server_ids` on the teammate, (3) enable the server (`PUT .../mcp-servers/{id}` with `{"enabled": true}`), and **only then** run `/health` and `/tools` probes while checking `body.status === "ok"`.
* **What I'd change in Chord next week:**
  1. Send stored static credentials on explicit admin `/health` and `/tools` probes even when `enabled: false` so builders can test a server before enabling it.
  2. Include `phase` (`initialize` vs. `tools/list`) and `remote_http_status` inside `/health` error payloads.

---

### Field Note 7: Dangerous Default in Teammate MCP Scoping (`mcp_server_ids: []` Grants Access to *All* Workspace Servers)

* **What happened:**
  - When updating a teammate (`PUT /v1/teammates/{id}`) in `ChordDeployCommand`, sending `{"set_mcp_server_ids": []}` is a no-op, and an empty `mcp_server_ids` array on a teammate is interpreted by Chord's runtime as **"allow ALL enabled MCP servers in the workspace."**
* **How I worked out the cause:**
  - Read `/docs/mcp-servers/#2-set-teammate-scope-then-enable` and `/docs/skills/#migration-and-release`: `connector_tool_types` has a `connector_tools_scoped` boolean flag to distinguish "unscoped (all)" from "explicitly empty (none)", whereas `mcp_server_ids` does not yet have an `mcp_servers_scoped` flag.
* **How I fixed it / worked around it:**
  - In `ChordDeployCommand`, explicitly bound the server ID via `{"set_mcp_server_ids": ["mcp-..."]}` *before* setting `enabled: true`, and passed an explicit `enabled_tools` allowlist (`["check_clinic_slots", "book_and_initialize_payment", "verify_payment_and_issue_pass", "lookup_or_cancel_booking"]`).
* **What I'd change in Chord next week:**
  - Add `mcp_servers_scoped: true` (or default `mcp_server_ids: []` to zero MCP servers) so newly created teammates follow least-privilege isolation.

---

### Field Note 8: Skill Draft vs. Published State & Dual Version Counters (`skill.version` vs. `published_version`)

* **What happened (including error messages):**
  - Creating a skill via `POST /v1/teammates/{identifier}/skills` returns `201 Created`, or clicking **Save draft** in the UI saves edits, but the teammate ignores the new `SKILL.md` in chat until explicitly published.
  - Passing `published_version` as `expected_version` on `PUT .../draft` or `POST .../publish` fails with HTTP `409 CONFLICT`.
* **How I worked out the cause:**
  - Inspected `GET /v1/teammates/{identifier}/skills` and `/docs/skills/#http-contract`: Chord tracks two separate counters (`skill.version` for optimistic concurrency on every draft/metadata write vs. `published_version` for immutable live releases).
* **How I fixed it / worked around it:**
  - In `ChordDeployCommand::deploySkillPackage()`, captured the `version` returned by `POST /skills` or `PUT /draft` and immediately chained `POST /v1/teammates/{id}/skills/{skill_id}/publish` with that exact `expected_version`.
* **What I'd change in Chord next week:**
  1. Add `"publish": true` as an optional flag on `POST /v1/teammates/{identifier}/skills` for atomic create-and-publish.
  2. Return `{"current_version": 4, "expected_version": 2}` in `error.details` on `409 CONFLICT`.

---

### Field Note 9: Live `api.chordhq.xyz` Gateway Probing — Mismatched `x-request-id` UUIDs, `camelCase` vs. `snake_case` Validation Trap, and `500` on Unsupported HTTP Verbs

* **What happened (including error messages):**
  1. **Mismatched Request IDs on `403 FORBIDDEN`:** Calling `GET https://api.chordhq.xyz/v1/teammates` without an inbound `X-Request-ID` header returned:
     ```
     HTTP/2 403
     x-request-id: c2fbe055-5182-43a3-b206-d15e9f1cd98b
     {"error":{"code":"FORBIDDEN","message":"Missing required permission: teammate:read","status":403},"requestId":"a2837c27-59d7-449d-90a7-53a622c67642"}
     ```
     The HTTP header `x-request-id` and JSON `requestId` contain **two different UUIDs**, contradicting `/docs/errors/#correlating-a-failed-request`.
  2. **`camelCase` Validation Keys vs. `snake_case` Jackson Trap:** Sending `{}` to `POST /v1/teammates/{id}/skills` returned `"details": {"idempotencyKey": "must not be blank"}` (camelCase), but sending `"idempotencyKey"` in JSON triggered `400 BAD_REQUEST: Malformed or unrecognized request body` until changed to `"idempotency_key"` (snake_case).
  3. **`500 INTERNAL_ERROR` on Unsupported HTTP Verb:** Sending `POST` to `/v1/teammates/{id}/tools` (which mounts `GET` and `PUT`) returned `500 INTERNAL_ERROR` instead of `405 METHOD_NOT_ALLOWED`.
* **How I worked out the cause:**
  - Probed `/v1/teammates`, `/v1/teammates/{id}/skills`, `/v1/teammates/{id}/tools`, and `/v1/workspaces/{id}/mcp-servers` with `curl -i`. Jackson uses `SNAKE_CASE` + `FAIL_ON_UNKNOWN_PROPERTIES`, while Bean Validation reports raw Java field names (`idempotencyKey`).
* **How I fixed it / worked around it:**
  - Updated `ChordDeployCommand` to always generate and send an explicit outbound `X-Request-ID` UUID (which forces the gateway and error body to echo the same UUID) and use strict `snake_case` JSON keys.
* **What I'd change in Chord next week:**
  1. Generate the request UUID once in the outermost filter when no inbound `X-Request-ID` header is present and reuse it in `@ControllerAdvice`.
  2. Pass Bean Validation property paths through Jackson's `PropertyNamingStrategies.SNAKE_CASE` before building `error.details`.
  3. Map `HttpRequestMethodNotSupportedException` to `405 METHOD_NOT_ALLOWED`.





