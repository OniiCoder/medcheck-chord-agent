<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ChordDeployCommand extends Command
{
    protected $signature = 'chord:deploy
                            {--mcp-url= : Public HTTPS URL for this Laravel MCP endpoint (e.g. https://xxxx.ngrok-free.app/mcp)}
                            {--teammate-id= : Override CHORD_TEAMMATE_ID from .env}
                            {--workspace-id= : Override CHORD_WORKSPACE_ID from .env}
                            {--skill-only : Only upload and publish the Chord Skill package}';

    protected $description = 'Upload & publish the MedCheck Skill package to Chord, register the Laravel Streamable HTTP MCP server, scope the teammate, and run health/tool discovery probes.';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('services.chord.base_url', 'https://api.chordhq.xyz'), '/');
        $apiKey = (string) config('services.chord.api_key');
        $teammateId = (string) ($this->option('teammate-id') ?: config('services.chord.teammate_id'));
        $workspaceId = (string) ($this->option('workspace-id') ?: config('services.chord.workspace_id'));
        $mcpUrl = (string) ($this->option('mcp-url') ?: (rtrim((string) config('app.url'), '/') . '/mcp'));
        $mcpBearer = (string) config('services.mcp.bearer_token');

        if (empty($apiKey)) {
            $this->error('CHORD_API_KEY is not set in .env yet.');
            $this->line('Once you receive your Chord account access & API key, add CHORD_API_KEY to .env and re-run:');
            $this->line('  php artisan chord:deploy --mcp-url=https://<your-public-domain>/mcp');
            return self::FAILURE;
        }

        $this->info("1. Verifying Chord API key against {$baseUrl}/v1/teammates...");
        $reqId = (string) Str::uuid();
        $tmListResp = Http::withToken($apiKey)
            ->withHeaders(['X-Request-ID' => $reqId])
            ->acceptJson()
            ->get("{$baseUrl}/v1/teammates");

        if (! $tmListResp->successful()) {
            $this->reportChordError('List teammates', $tmListResp);
            return self::FAILURE;
        }

        $teammates = $tmListResp->json() ?? [];
        if (empty($teammateId) && ! empty($teammates[0]['id'])) {
            $teammateId = $teammates[0]['id'];
            $workspaceId = $workspaceId ?: ($teammates[0]['workspace_id'] ?? '');
            $this->info("   Auto-selected Teammate ID: {$teammateId} (Workspace: {$workspaceId})");
        }

        if (empty($teammateId)) {
            $this->error('No teammate found in workspace. Create a teammate in the Chord UI first or pass --teammate-id=tm-...');
            return self::FAILURE;
        }

        // Fetch teammate details to confirm workspace_id and lifecycle_state
        $tmResp = Http::withToken($apiKey)
            ->withHeaders(['X-Request-ID' => (string) Str::uuid()])
            ->acceptJson()
            ->get("{$baseUrl}/v1/teammates/{$teammateId}");

        if ($tmResp->successful()) {
            $tmData = $tmResp->json();
            $workspaceId = $workspaceId ?: ($tmData['workspace_id'] ?? '');
            $this->info("   Teammate: {$tmData['display_name']} ({$tmData['id']}) | State: " . ($tmData['lifecycle_state'] ?? 'unknown'));
        }

        // 2. Upload & Publish the Chord Skill Package
        $this->info('2. Uploading and publishing chord-skill package (SKILL.md + references/*)...');
        if (! $this->deploySkillPackage($baseUrl, $apiKey, $teammateId)) {
            return self::FAILURE;
        }

        if ($this->option('skill-only')) {
            $this->info('Skill deployment complete (--skill-only specified).');
            return self::SUCCESS;
        }

        if (empty($workspaceId)) {
            $this->error('CHORD_WORKSPACE_ID is required to register an MCP server.');
            return self::FAILURE;
        }

        if (! str_starts_with($mcpUrl, 'https://')) {
            $this->warn("WARNING: Chord's outbound URL policy blocks non-HTTPS and private/loopback URLs ({$mcpUrl}).");
            $this->warn('Expose this Laravel app with `herd share` or `ngrok http 8000` and pass --mcp-url=https://.../mcp');
        }

        // 3. Register or update MCP Server
        $this->info("3. Registering Streamable HTTP MCP Server ({$mcpUrl})...");
        $mcpServerId = $this->registerAndEnableMcpServer($baseUrl, $apiKey, $workspaceId, $teammateId, $mcpUrl, $mcpBearer);
        if (! $mcpServerId) {
            return self::FAILURE;
        }

        $this->info("SUCCESS! MedCheck Diagnostics Lekki agent is deployed and verified on Chord (MCP ID: {$mcpServerId}).");
        return self::SUCCESS;
    }

    private function deploySkillPackage(string $baseUrl, string $apiKey, string $teammateId): bool
    {
        $skillDir = base_path('chord-skill');
        $filesPayload = [
            [
                'path' => 'SKILL.md',
                'content' => File::get("{$skillDir}/SKILL.md"),
            ],
            [
                'path' => 'references/test_catalog_and_prep.md',
                'content' => File::get("{$skillDir}/references/test_catalog_and_prep.md"),
            ],
            [
                'path' => 'references/clinic_policies_and_hmo.md',
                'content' => File::get("{$skillDir}/references/clinic_policies_and_hmo.md"),
            ],
        ];

        // Check existing skills on teammate
        $existingResp = Http::withToken($apiKey)
            ->withHeaders(['X-Request-ID' => (string) Str::uuid()])
            ->acceptJson()
            ->get("{$baseUrl}/v1/teammates/{$teammateId}/skills");

        if (! $existingResp->successful()) {
            $this->reportChordError('GET teammate skills', $existingResp);
            return false;
        }

        $skillsState = $existingResp->json();
        if (! ($skillsState['enabled'] ?? true)) {
            $this->line('   Migrating teammate to file-based skills...');
            Http::withToken($apiKey)
                ->withHeaders(['X-Request-ID' => (string) Str::uuid()])
                ->acceptJson()
                ->post("{$baseUrl}/v1/teammates/{$teammateId}/skills/migrate");
        }

        $existingSkill = collect($skillsState['skills'] ?? [])->firstWhere('name', 'MedCheck Diagnostics Lekki Booking Desk');
        $skillId = $existingSkill['id'] ?? null;
        $expectedVersion = $existingSkill['version'] ?? null;

        if (! $skillId) {
            $createResp = Http::withToken($apiKey)
                ->withHeaders(['X-Request-ID' => (string) Str::uuid()])
                ->acceptJson()
                ->post("{$baseUrl}/v1/teammates/{$teammateId}/skills", [
                    'name' => 'MedCheck Diagnostics Lekki Booking Desk',
                    'entrypoint' => 'SKILL.md',
                    'files' => $filesPayload,
                    'idempotency_key' => 'medcheck-skill-create-' . md5(json_encode($filesPayload)),
                ]);

            if (! $createResp->successful()) {
                $this->reportChordError('Create skill draft', $createResp);
                return false;
            }

            $created = $createResp->json();
            $skillId = $created['id'] ?? ($created['skill']['id'] ?? null);
            $expectedVersion = $created['version'] ?? ($created['skill']['version'] ?? 1);
            $this->info("   Created skill draft: {$skillId} (version {$expectedVersion})");
        } else {
            $draftResp = Http::withToken($apiKey)
                ->withHeaders(['X-Request-ID' => (string) Str::uuid()])
                ->acceptJson()
                ->put("{$baseUrl}/v1/teammates/{$teammateId}/skills/{$skillId}/draft", [
                    'name' => 'MedCheck Diagnostics Lekki Booking Desk',
                    'entrypoint' => 'SKILL.md',
                    'files' => $filesPayload,
                    'expected_version' => $expectedVersion,
                    'idempotency_key' => 'medcheck-skill-draft-' . $expectedVersion . '-' . md5(json_encode($filesPayload)),
                ]);

            if (! $draftResp->successful()) {
                $this->reportChordError('Update skill draft', $draftResp);
                return false;
            }

            $updated = $draftResp->json();
            $expectedVersion = $updated['version'] ?? ($updated['skill']['version'] ?? ($expectedVersion + 1));
            $this->info("   Updated skill draft: {$skillId} (version {$expectedVersion})");
        }

        // Publish the reviewed draft (A saved draft is NOT live until published!)
        $publishResp = Http::withToken($apiKey)
            ->withHeaders(['X-Request-ID' => (string) Str::uuid()])
            ->acceptJson()
            ->post("{$baseUrl}/v1/teammates/{$teammateId}/skills/{$skillId}/publish", [
                'expected_version' => $expectedVersion,
                'summary' => 'Publish MedCheck Lekki clinical prep, slot booking, and Paystack verification skill',
                'idempotency_key' => "medcheck-skill-pub-{$skillId}-v{$expectedVersion}",
            ]);

        if (! $publishResp->successful()) {
            $this->reportChordError('Publish skill revision', $publishResp);
            return false;
        }

        $this->info("   Published skill {$skillId} live!");
        return true;
    }

    private function registerAndEnableMcpServer(
        string $baseUrl,
        string $apiKey,
        string $workspaceId,
        string $teammateId,
        string $mcpUrl,
        string $mcpBearer
    ): ?string {
        $regPayload = [
            'name' => 'MedCheck Diagnostics Tools',
            'url' => $mcpUrl,
            'auth_type' => ! empty($mcpBearer) ? 'bearer' : 'none',
            'transport' => 'streamable_http',
            'enabled' => false,
        ];
        if (! empty($mcpBearer)) {
            $regPayload['auth_token'] = $mcpBearer;
        }

        $regResp = Http::withToken($apiKey)
            ->withHeaders(['X-Request-ID' => (string) Str::uuid()])
            ->acceptJson()
            ->post("{$baseUrl}/v1/workspaces/{$workspaceId}/mcp-servers", $regPayload);

        if ($regResp->status() === 409) {
            // Server name already registered; find existing MCP server
            $listResp = Http::withToken($apiKey)
                ->acceptJson()
                ->get("{$baseUrl}/v1/workspaces/{$workspaceId}/mcp-servers");
            $existing = collect($listResp->json() ?? [])->firstWhere('name', 'MedCheck Diagnostics Tools');
            $mcpServerId = $existing['id'] ?? null;
        } elseif ($regResp->successful()) {
            $mcpServerId = $regResp->json('id');
        } else {
            $this->reportChordError('Register MCP Server', $regResp);
            return null;
        }

        if (! $mcpServerId) {
            $this->error('Could not resolve MCP Server ID.');
            return null;
        }

        $this->info("   MCP Server ID: {$mcpServerId}");

        // Scope teammate to this MCP server before enabling
        $scopeResp = Http::withToken($apiKey)
            ->withHeaders(['X-Request-ID' => (string) Str::uuid()])
            ->acceptJson()
            ->put("{$baseUrl}/v1/teammates/{$teammateId}", [
                'set_mcp_server_ids' => [$mcpServerId],
            ]);

        if (! $scopeResp->successful()) {
            $this->reportChordError('Set teammate MCP scope', $scopeResp);
            return null;
        }

        // Enable MCP server with explicit enabled_tools allowlist
        $enablePayload = [
            'url' => $mcpUrl,
            'enabled' => true,
            'enabled_tools' => [
                'check_clinic_slots',
                'book_and_initialize_payment',
                'verify_payment_and_issue_pass',
                'lookup_or_cancel_booking',
            ],
        ];
        if (! empty($mcpBearer)) {
            $enablePayload['auth_token'] = $mcpBearer;
        }

        $enableResp = Http::withToken($apiKey)
            ->withHeaders(['X-Request-ID' => (string) Str::uuid()])
            ->acceptJson()
            ->put("{$baseUrl}/v1/workspaces/{$workspaceId}/mcp-servers/{$mcpServerId}", $enablePayload);

        if (! $enableResp->successful()) {
            $this->reportChordError('Enable MCP Server', $enableResp);
            return null;
        }

        // Probe /health and /tools (Remember: Chord returns HTTP 200 even on health failure, so inspect status!)
        $this->info('4. Probing MCP server /health and /tools via Chord...');
        $healthResp = Http::withToken($apiKey)
            ->withHeaders(['X-Request-ID' => (string) Str::uuid()])
            ->acceptJson()
            ->post("{$baseUrl}/v1/workspaces/{$workspaceId}/mcp-servers/{$mcpServerId}/health");

        $healthBody = $healthResp->json();
        if (($healthBody['status'] ?? '') !== 'ok') {
            $this->warn('   Health probe returned non-ok status inside HTTP ' . $healthResp->status() . ': ' . json_encode($healthBody));
        } else {
            $this->info('   Health probe OK (latency: ' . ($healthBody['latency_ms'] ?? '?') . 'ms)');
        }

        $toolsResp = Http::withToken($apiKey)
            ->withHeaders(['X-Request-ID' => (string) Str::uuid()])
            ->acceptJson()
            ->post("{$baseUrl}/v1/workspaces/{$workspaceId}/mcp-servers/{$mcpServerId}/tools");

        if ($toolsResp->successful()) {
            $toolNames = collect($toolsResp->json() ?? [])->pluck('name')->implode(', ');
            $this->info("   Discovered tools: [{$toolNames}]");
        } else {
            $this->reportChordError('Discover MCP tools', $toolsResp);
        }

        return $mcpServerId;
    }

    private function reportChordError(string $step, \Illuminate\Http\Client\Response $response): void
    {
        $body = $response->json();
        $code = $body['error']['code'] ?? 'HTTP_' . $response->status();
        $msg = $body['error']['message'] ?? $response->body();
        $reqId = $body['requestId'] ?? $response->header('X-Request-Id');
        $details = isset($body['error']['details']) ? json_encode($body['error']['details']) : '';

        $this->error("{$step} failed: [{$code}] {$msg} (requestId: {$reqId}) {$details}");
    }
}
