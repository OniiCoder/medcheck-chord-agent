# Deployment Field Notes — MedCheck Diagnostics Chord Agent

**Candidate Role:** Forward-Deployed Engineer

Below are the specific technical issues, subtle API contract edge cases, and documentation gaps encountered while building and deploying the MedCheck Diagnostics agent on Chord, along with how each root cause was isolated and concrete recommendations for the Chord engineering team.

---

### Field Note 1: MCP `streamable_http` Registration Succeeds (`201 Created`) While Subsequent `/tools` & `/health` Probes Fail Due to CSRF, Static-Auth State, and Notification Status Codes

* **What happened (including error messages):**
  1. When first connecting a Laravel-backed MCP endpoint (`/mcp`) to Chord via `POST /v1/workspaces/{ws}/mcp-servers`, registration returned `201 Created` (`{"id": "mcp-...", "enabled": false, "transport": "streamable_http"}`).
  2. However, probing `POST /v1/workspaces/{ws}/mcp-servers/{id}/health` right after registration while `enabled: false` (or hitting `/mcp` before configuring Laravel middleware exemptions) resulted in HTTP `200 OK` from Chord containing `{"status": "error", "error": "..."}` or `502 BAD_GATEWAY` on `/tools`.
  3. Specifically, on a standard Laravel 11–13 stack, routes in `routes/web.php` apply `VerifyCsrfToken` (`419 Page Expired`) by default, and MCP `notifications/initialized` messages (which omit the JSON-RPC `id` field) cause naive JSON-RPC servers to return an invalid JSON-RPC response object with `"id": null` instead of `202 Accepted`.

* **How I worked out the cause:**
  - I logged every inbound request to `/mcp` in an SQLite `mcp_audit_logs` table and cross-referenced the behavior against the **MCP connections** docs (`/docs/mcp-servers/#3-discover-tools-and-check-health`).
  - Two distinct mechanics were at play:
    1. **Chord's probe credential gating**: As noted in a single sentence in section 3 of the docs, *"a disabled server or workspace does not supply its stored bearer/API-key credential to these probes."* Following the Step 1 example (`"enabled": false` with `"auth_type": "bearer"`) and probing before enabling causes Chord to omit the `Authorization: Bearer` header during the probe, triggering a `401` on the remote MCP server!
    2. **Chord's HTTP 200 wrapper on `/health`**: `POST .../health` returns HTTP `200` even when the underlying MCP handshake fails, embedding `"status": "error"` inside the JSON body.

* **How I fixed it / worked around it:**
  - In `bootstrap/app.php`, explicitly exempted `mcp` and `mcp/*` from CSRF verification and forced JSON exception rendering on `mcp*`.
  - In `McpServerController.php`, added explicit handling for JSON-RPC notifications (messages without an `id` key, such as `notifications/initialized`) to return HTTP `202 Accepted` with `Mcp-Session-Id`, and dynamically negotiated `protocolVersion` (`2024-11-05` / `2025-03-26`).
  - In `ChordDeployCommand.php` (`php artisan chord:deploy`), ordered the provisioning pipeline to: (1) register disabled, (2) scope `set_mcp_server_ids` on the teammate, (3) enable the server (`PUT .../mcp-servers/{id}` with `{"enabled": true}`), and **only then** run `/health` and `/tools` probes while asserting `body.status === "ok"`.

* **What I'd change in Chord, its docs, or its API next week:**
  1. **Send stored static credentials on explicit admin `/health` and `/tools` probes even when `enabled: false`**: The whole point of registering a server with `enabled: false` (as recommended in Step 1 of `/docs/mcp-servers/`) is to verify that credentials and tool discovery work *before* exposing the server to live teammates. Stripping `auth_token` when `enabled: false` forces builders to enable an untested server in the workspace just to run a health check.
  2. **Surface remote HTTP status & JSON-RPC phase in `/health` errors**: When a probe fails, return structured diagnostics such as `{"status": "error", "phase": "initialize", "remote_http_status": 401, "error": "..."}` so builders immediately know whether TLS, Bearer auth, `initialize`, or `tools/list` failed.

---

### Field Note 2: Dangerous Default in Teammate MCP Scoping (`mcp_server_ids: []` Grants Access to *All* Workspace Servers)

* **What happened (including error messages):**
  - While building the automated deployment script (`PUT /v1/teammates/{id}`), I tested how to isolate a teammate's MCP access and how to clear an MCP server assignment by sending `{"set_mcp_server_ids": []}`.
  - Instead of revoking MCP tool access (or clearing the list), `set_mcp_server_ids: []` is a no-op on update, and an empty/omitted `mcp_server_ids` array on a teammate is interpreted by the runtime as **"allow ALL enabled MCP servers in the workspace."**

* **How I worked out the cause:**
  - Read `/docs/mcp-servers/#2-set-teammate-scope-then-enable` and `/docs/skills/#migration-and-release`, which notes that historically an empty selection meant "all workspace tools," whereas `connector_tool_types` recently gained a separate `connector_tools_scoped` boolean flag to distinguish "unscoped (all)" from "explicitly empty (none)." `mcp_server_ids` does not yet have an equivalent `mcp_servers_scoped` boolean.

* **How I fixed it / worked around it:**
  - In `ChordDeployCommand.php`, we explicitly bind the newly created `mcp-...` ID via `{"set_mcp_server_ids": ["mcp-..."]}` *before* flipping `enabled: true` on the workspace MCP server, and we also pass an explicit `enabled_tools` allowlist (`["check_clinic_slots", "book_and_initialize_payment", "verify_payment_and_issue_pass", "lookup_or_cancel_booking"]`) on the server record itself.

* **What I'd change in Chord, its docs, or its API next week:**
  - Add `mcp_servers_scoped: true` (matching `connector_tools_scoped` on `/v1/teammates/{id}/tools`) or change the default for newly created teammates so `mcp_server_ids: []` means **zero** MCP servers rather than **every enabled MCP server in the workspace** (principle of least privilege).

---

### Field Note 3: Skill Draft vs. Published State & Dual Version Counters (`skill.version` vs. `published_version`)

* **What happened (including error messages):**
  - Creating a skill via `POST /v1/teammates/{identifier}/skills` returns `201 Created` with the uploaded `SKILL.md` and `references/*` files, or clicking **Save draft** in the UI saves the edits. However, talking to the teammate immediately afterward produces replies that ignore the new `SKILL.md` and cannot read `references/test_catalog_and_prep.md`.
  - Furthermore, when automating `PUT /v1/teammates/{id}/skills/{skill_id}/draft` followed by `POST .../publish`, using `published_version` as `expected_version` fails with HTTP `409 CONFLICT`.

* **How I worked out the cause:**
  - Inspected the response payload of `GET /v1/teammates/{identifier}/skills` and `/docs/skills/#http-contract`.
  - Chord maintains two separate integer counters on a skill: `skill.version` (the optimistic concurrency CAS token incremented on *every* draft save or metadata mutation) and `published_version` (incremented only on immutable publish revisions). And `POST /v1/teammates/{id}/skills` creates an **unpublished draft** (`no live instructions yet`).

* **How I fixed it / worked around it:**
  - Updated `ChordDeployCommand::deploySkillPackage()` to capture the fresh `version` returned by the `POST` (create) or `PUT /draft` call and immediately chain `POST /v1/teammates/{id}/skills/{skill_id}/publish` with that exact `expected_version` and a deterministic `idempotency_key`.

* **What I'd change in Chord, its docs, or its API next week:**
  1. Add `"publish": true` as an optional boolean on `POST /v1/teammates/{identifier}/skills` so API builders can create and publish v1 atomically in one HTTP call (matching how the Web UI's "Create a teammate from files" flow publishes v1 on creation).
  2. On `409 CONFLICT` responses from skill mutations, include `{"current_version": 4, "expected_version": 2}` inside `error.details` so clients know immediately whether they mixed up `published_version` and `version`.

---

### Field Note 4: Broken Context Link in Exercise Brief & SSR 403 on `tsglabs.com`

* **What happened (including error messages):**
  - Clicking the exercise brief's first resource link (`https://tsglabs.com/blog/announcing-tsg-labs`) returns `HTTP 404 Not Found`.
  - Visiting `https://tsglabs.com/` renders an empty **"Updates from the Lab"** blog section, and inspecting the SSR `__NUXT_DATA__` payload reveals a live error:
    `{"statusCode": 403, "statusMessage": "Forbidden", "message": "[GET] \"/api/blog/posts\": 403 Forbidden"}`.

* **How I worked out the cause:**
  - Fetched `https://tsglabs.com/` and inspected the serialized Nuxt SSR state at the bottom of the HTML document, which shows the Nuxt server-side fetch to `/api/blog/posts` (likely backed by Directus at `directus.paystack.co`) failing with `403 Forbidden` due to an expired or missing public read token.

* **How I fixed it / worked around it:**
  - Used `https://api.chordhq.xyz/docs` and the `paystack-index` interactive terminal embedded on `tsglabs.com` for platform context.

* **What I'd change next week:**
  - Rotate/restore the Directus public read token or permissions backing `GET /api/blog/posts` on `tsglabs.com` so the blog section and the `announcing-tsg-labs` post in the candidate brief resolve properly.

---

### Field Note 5: Paystack Test Mode `400 Bad Request` (`"Currency not supported by merchant"`) When Hardcoding `'currency' => 'NGN'`

* **What happened (including error messages):**
  - When testing `book_and_initialize_payment` with our live Paystack test key (`sk_test_...`), our initial implementation sent `'currency' => 'NGN'` to `POST https://api.paystack.co/transaction/initialize`.
  - Paystack rejected the call with HTTP `400`:
    `{"status": false, "message": "Currency not supported by merchant"}`, which our MCP server surfaced as `{"isError": true, "error_code": "PAYSTACK_INIT_REJECTED", "message": "Currency not supported by merchant"}`.

* **How I worked out the cause:**
  - Ran an interactive `tinker` probe against `POST https://api.paystack.co/transaction/initialize` omitting the `currency` field, followed by `GET https://api.paystack.co/transaction/verify/{reference}`.
  - The verification payload revealed `"currency": "GHS"` (`domain: "test"`) — our Paystack test merchant account is domiciled in Ghana (`GHS`), where `NGN` is not enabled by default on new test merchant profiles.

* **How I fixed it / worked around it:**
  - Updated `PaystackService::initializeTransaction()` so `currency` is optional (defaulting to the merchant's primary settlement currency on Paystack when `PAYSTACK_CURRENCY` is unset) and added an automatic retry that strips `currency` if Paystack responds with `"Currency not supported by merchant"`.
  - Immediately re-tested via MCP `tools/call` → `book_and_initialize_payment`, which succeeded (`"checkout_url": "https://checkout.paystack.com/nt596adh12eomxm"`) and verified cleanly against `GET /transaction/verify/PSK-MCK-...` (`"paystack_payment_status": "abandoned"`, `"gateway_response": "The transaction was not completed"`).

* **What I'd change in Chord / Exercise Starter Docs next week:**
  - Since TSG Labs hires across Nigeria, Ghana, Kenya, and South Africa, add a one-line note in the exercise brief or a Paystack MCP recipe in `api.chordhq.xyz/docs` reminding builders not to hardcode `currency: "NGN"` on Paystack `/transaction/initialize` when using regional test keys, or provide a built-in Paystack Test Connector in Chord's workspace tool catalog.

---

### Field Note 6: Live `api.chordhq.xyz` Gateway Probing — Mismatched `x-request-id` UUIDs, `camelCase` vs. `snake_case` Validation Trap, and Pre-Auth Bean Validation

* **What happened (including error messages):**
  1. **API Key Scope Rejection (`403 FORBIDDEN`) & Mismatched Request IDs:**
     - Calling `GET https://api.chordhq.xyz/v1/teammates` with our initial `chord_live_...` API key (without an inbound `X-Request-ID` header) returned HTTP `403`:
       ```
       HTTP/2 403
       x-request-id: c2fbe055-5182-43a3-b206-d15e9f1cd98b
       {"error":{"code":"FORBIDDEN","message":"Missing required permission: teammate:read","status":403},"requestId":"a2837c27-59d7-449d-90a7-53a622c67642"}
       ```
     - Notice that when the caller does not send an inbound `X-Request-ID` header, the HTTP response header `x-request-id` (`c2fbe055-...`) and the JSON error body `requestId` (`a2837c27-...`) contain **two different UUIDs**, directly contradicting `/docs/errors/#correlating-a-failed-request` (*"Every error response carries the same identifier in two places"*).
  2. **`camelCase` Validation Error Keys vs. `snake_case` Jackson Deserialization Trap on `POST /v1/teammates/{id}/skills`:**
     - Sending `{}` to `POST /v1/teammates/{id}/skills` returned:
       `{"error":{"code":"VALIDATION_ERROR","message":"Request validation failed","status":400,"details":{"name":"must not be blank","idempotencyKey":"must not be blank","entrypoint":"must not be blank","files":"must not be empty"}}}`
     - However, sending `"idempotencyKey"` (camelCase, as instructed by `error.details`) caused Jackson to reject the payload with:
       `{"error":{"code":"BAD_REQUEST","message":"Malformed or unrecognized request body","status":400}}`
       Sending `"idempotency_key"` (snake_case) passed deserialization and advanced to nested validation (`"files[0].path": "must not be blank"`).
  3. **Bean Validation (`@Valid`) Executing Before Permission Checks (`@PreAuthorize`) & `500` on Unsupported HTTP Verbs:**
     - On `POST /v1/teammates`, `POST /v1/teammates/{id}/skills`, and `POST /v1/workspaces/{id}/mcp-servers`, an API key lacking write permissions still receives `400 VALIDATION_ERROR` (exposing DTO field constraints) before `403 FORBIDDEN` is checked once the body is valid.
     - Calling `POST /v1/teammates/{id}/tools` (where `GET` and `PUT` are mounted) returns `500 INTERNAL_ERROR` (`{"error":{"code":"INTERNAL_ERROR","message":"An unexpected error occurred","status":500}}`) instead of `405 METHOD_NOT_ALLOWED`.

* **How I worked out the cause:**
  - Ran `curl -i` probes against `https://api.chordhq.xyz` across `/v1/teammates`, `/v1/teammates/{id}/skills`, `/v1/teammates/{id}/tools`, and `/v1/workspaces/{id}/mcp-servers`.
  - Identified that Jackson is configured with `PropertyNamingStrategies.SNAKE_CASE` + `DeserializationFeature.FAIL_ON_UNKNOWN_PROPERTIES`, while the Bean Validation exception mapper formats `ConstraintViolation.getPropertyPath()` using the raw Java/Kotlin field name (`idempotencyKey`), creating a trap where following `error.details` triggers a `400 Malformed or unrecognized request body`.
  - Verified that supplying an explicit outbound `X-Request-ID` header (as our `php artisan chord:deploy` command does) forces the gateway and error body to echo the same correlation ID.

* **How I fixed it / worked around it:**
  - Configured `php artisan chord:deploy` (`app/Console/Commands/ChordDeployCommand.php`) to always generate and send an explicit `X-Request-ID` UUID on every request, and to use strict `snake_case` keys (`idempotency_key`, `expected_version`, `enabled_tools`, `set_mcp_server_ids`).

* **What I'd change in Chord next week:**
  1. **Unify `x-request-id` generation:** Generate the request UUID once in the outermost servlet/gateway filter when no inbound `X-Request-ID` is present, store it in `HttpServletRequest` / MDC, and reuse that exact UUID in the global `@ControllerAdvice` error serializer so `x-request-id` header and JSON `requestId` always match.
  2. **Translate `VALIDATION_ERROR` field names to `snake_case`:** In the `MethodArgumentNotValidException` handler, map Java property paths through Jackson's `PropertyNamingStrategies.SNAKE_CASE` before populating `error.details` so `"idempotencyKey"` is reported as `"idempotency_key"`.
  3. **Map `HttpRequestMethodNotSupportedException` to `405`:** Add a handler in `@ControllerAdvice` so calling `POST` on a `PUT`-only route (`/v1/teammates/{id}/tools`) returns `405 METHOD_NOT_ALLOWED` rather than `500 INTERNAL_ERROR`.


