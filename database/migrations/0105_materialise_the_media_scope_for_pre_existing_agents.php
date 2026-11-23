<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Psr\Log\NullLogger;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Models\Principal;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigService;
use Spora\Tools\MediaTool;

/**
 * The media tool's `scope` setting keeps the reach it had.
 *
 * The setting's default moves from `agent` to `principal`, and the
 * default is what an agent falls back to when no layer of the settings
 * cascade supplies a scope. Enabling a tool materialises the schema
 * default into the agent's own override row — but only under a
 * condition: {@see \Spora\Services\AgentToolSettingsService::enableTool()}
 * skips the materialisation when the shared group has values for the
 * tool, when the global blob holds anything at all, or when the agent
 * already has an override row. An agent that had the `media` tool
 * enabled before this change can therefore end up with **no stored
 * scope anywhere**, and its effective scope silently widens from "what
 * this agent produced" to "everything the principal owns" — with no
 * operator action and no visible sign.
 *
 * The fix is to materialise `scope: 'agent'` for the agents that are in
 * exactly that state, so their reach is what it was and the operator
 * can still widen it deliberately.
 *
 * **Why adding a value is safe where rewriting one is not.** A
 * materialised default and a deliberate choice are the same string, so
 * a migration that *rewrote* `scope: 'agent'` into `scope: 'principal'`
 * could not tell the two apart and would overturn the operators who
 * chose `agent` on purpose. This migration never rewrites: it only
 * adds a scope where **no** scope exists anywhere. Absence is
 * unambiguous — there is no deliberate choice to distinguish — and the
 * condition below excludes every case where a deliberate choice does
 * exist.
 *
 * **The condition.** An agent is materialised when all three hold:
 *
 *   1. the `media` tool is enabled for it;
 *   2. its own override row holds no scope of its own;
 *   3. no other layer supplies one — not the shared group, not the
 *      global layer, not a principal layer.
 *
 * Clause 3 is what keeps a deliberate choice intact. A global (or
 * principal, or shared group) scope governs today and keeps governing;
 * writing `agent` into the agent's own row would shadow it and narrow
 * the reach the operator asked for, which is a different defect. Only
 * where nothing supplies a scope is the schema default the only thing
 * in play — and there the pre-change answer was `agent`.
 *
 * **How the effective scope is determined.** Through the live cascade,
 * read with {@see ToolConfigService::getEffectiveSettingsWithSource()},
 * which reports which layer supplied each value. The migration does not
 * re-implement the cascade: a resolution that drifts from the runtime
 * would materialise the wrong scope, and the drift would be invisible.
 * Every principal that could govern the agent is resolved — the
 * principal that owns it, and every principal its owner can act for —
 * because a layer that supplies a scope must never be shadowed, and a
 * superset of the governing layers is the safe direction.
 *
 * **Idempotency.** An agent that already holds a scope is left alone,
 * so a second run finds nothing to do; the marker table records the
 * rows this migration wrote, which makes the second run skip them
 * without even reading their settings.
 *
 * **`down()` reverses only what `up()` wrote.** The rows are recorded
 * in `media_scope_materialisations` together with the settings the row
 * held before, so the reversal restores exactly those rows and leaves
 * every other agent's scope — the operator's own — untouched. A blanket
 * removal would strip a scope the operator set on purpose, which is the
 * mistake this migration exists to avoid.
 */
return new class extends Migration {
    /** The tool whose `scope` setting this migration freezes. */
    private const TOOL_CLASS = MediaTool::class;

    /** The scope an agent had before the default moved. */
    private const PRE_UPGRADE_SCOPE = 'agent';

    /** The rows this migration wrote, so `down()` can undo exactly those. */
    private const MARKER_TABLE = 'media_scope_materialisations';

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function up(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('agent_tools') || !$schema->hasTable('agent_tool_overrides')) {
            return;
        }

        $this->ensureMarkerTable();

        $toolConfig = $this->toolConfig();
        $resolver   = new PrincipalResolver();
        $principals = new PrincipalService($resolver);

        foreach ($this->agentsWithMediaTool() as $agentId) {
            try {
                $this->materialise($toolConfig, $principals, $resolver, $agentId);
            } catch (Throwable $e) {
                // A settings blob this migration cannot read is left alone:
                // writing over it would destroy settings the operator
                // stored, and leaving it alone keeps the agent exactly as
                // it is — an operator can still set its scope by hand.
                error_log(sprintf(
                    'Migration 0105: agent %d was left alone — its media scope could not be read (%s).',
                    $agentId,
                    $e->getMessage(),
                ));
            }
        }
    }

    public function down(): void
    {
        if (!Capsule::schema()->hasTable(self::MARKER_TABLE)) {
            return;
        }

        $toolConfig = $this->toolConfig();

        foreach (Capsule::table(self::MARKER_TABLE)->orderBy('agent_id')->get() as $marker) {
            $agentId = (int) $marker->agent_id;
            $prior   = $marker->prior_settings === null
                ? null
                : json_decode((string) $marker->prior_settings, true);

            if (!is_array($prior)) {
                // The row did not exist before this migration wrote it.
                $toolConfig->deleteAgentOverride(self::TOOL_CLASS, $agentId);

                continue;
            }

            // `putAgentOverride()` merges, so it cannot take a key away.
            // The row is written back wholesale, through the service's own
            // encoder so the blob is the one the service would have
            // written.
            Capsule::table('agent_tool_overrides')
                ->where('agent_id', $agentId)
                ->where('tool_class', self::TOOL_CLASS)
                ->update([
                    'settings'   => $toolConfig->encryptSettings(self::TOOL_CLASS, $prior),
                    'updated_at' => date(self::DATETIME_FORMAT),
                ]);
        }

        Capsule::schema()->dropIfExists(self::MARKER_TABLE);
    }

    /**
     * Materialise the pre-change scope for one agent, when nothing else
     * supplies a scope.
     */
    private function materialise(
        ToolConfigService $toolConfig,
        PrincipalService $principals,
        PrincipalResolver $resolver,
        int $agentId,
    ): void {
        if ($this->alreadyMaterialised($agentId)) {
            return;
        }

        // The agent's own row is the top layer of every resolution below,
        // so a scope it holds is reported as supplied — and a scope it
        // does not hold leaves the layer below to answer.
        if ($this->scopeSupplied($toolConfig, $agentId, null)) {
            return;
        }

        foreach ($this->governingPrincipalIds($agentId, $principals, $resolver) as $principalId) {
            if ($this->scopeSupplied($toolConfig, $agentId, $principalId)) {
                return;
            }
        }

        $prior = $toolConfig->getRawAgentOverride(self::TOOL_CLASS, $agentId);

        $toolConfig->putAgentOverride(self::TOOL_CLASS, $agentId, ['scope' => self::PRE_UPGRADE_SCOPE]);

        $this->record($agentId, $prior);
    }

    /**
     * Whether some layer supplies a scope the agent's own row must not
     * shadow.
     *
     * The resolution reports the layer that wrote each value and the
     * value itself, and both matter: the source says whether a layer
     * answered at all, and the value says whether the answer means
     * anything. A layer that holds nothing, or that holds the cleared
     * marker the cascade reads as "inherit", supplies no scope — the
     * schema default is what stands, and the schema default is what
     * changed.
     */
    private function scopeSupplied(ToolConfigService $toolConfig, int $agentId, ?int $principalId): bool
    {
        $context = null;
        if ($principalId !== null) {
            $type = Capsule::table('principals')->where('id', $principalId)->value('type');
            $context = new PrincipalContext(
                principalId: $principalId,
                type: is_string($type) ? $type : Principal::TYPE_USER,
                ownerUserId: null,
                runnerUserId: null,
            );
        }

        $annotated = $toolConfig->getEffectiveSettingsWithSource(self::TOOL_CLASS, $agentId, null, $context);

        $entry = $annotated['scope'] ?? null;
        if (!is_array($entry)) {
            return false;
        }

        if ($entry['source'] === 'default') {
            return false;
        }

        $value = $entry['value'];

        return $value !== null && $value !== '';
    }

    /**
     * The principals whose settings can govern this agent's scope.
     *
     * A superset of what the runtime consults: the principal that owns
     * the agent, plus every principal its owner user can act for. The
     * runtime resolves the owner user — and, with the group cascade on,
     * the owner's group principals — while the principal that owns the
     * agent is the row a group-scoped operator setting lives in. A
     * superset is the safe direction: a layer that supplies a scope
     * must never be shadowed by the agent's own row.
     *
     * @return list<int>
     */
    private function governingPrincipalIds(
        int $agentId,
        PrincipalService $principals,
        PrincipalResolver $resolver,
    ): array {
        $principalId = Capsule::table('agents')->where('id', $agentId)->value('principal_id');
        if ($principalId === null) {
            return [];
        }

        $ids = [(int) $principalId];

        $ownerUserId = $resolver->ownerUserId((int) $principalId);
        if ($ownerUserId !== null) {
            $ids = array_merge($ids, $principals->principalIdsForUser($ownerUserId));
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private function agentsWithMediaTool(): array
    {
        return Capsule::table('agent_tools')
            ->where('tool_class', self::TOOL_CLASS)
            ->distinct()
            ->pluck('agent_id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->all();
    }

    private function alreadyMaterialised(int $agentId): bool
    {
        return Capsule::table(self::MARKER_TABLE)->where('agent_id', $agentId)->exists();
    }

    /**
     * Record the row this migration wrote, and the settings it held
     * before, so `down()` can restore exactly that row.
     *
     * @param array<string, mixed> $prior
     */
    private function record(int $agentId, array $prior): void
    {
        $rowExisted = Capsule::table('agent_tool_overrides')
            ->where('agent_id', $agentId)
            ->where('tool_class', self::TOOL_CLASS)
            ->exists();

        Capsule::table(self::MARKER_TABLE)->insert([
            'agent_id'       => $agentId,
            'prior_settings' => $rowExisted
                ? json_encode($prior, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT)
                : null,
            'created_at'     => date(self::DATETIME_FORMAT),
        ]);
    }

    private function ensureMarkerTable(): void
    {
        $schema = Capsule::schema();
        if ($schema->hasTable(self::MARKER_TABLE)) {
            return;
        }

        $schema->create(self::MARKER_TABLE, static function (Blueprint $table): void {
            $table->unsignedBigInteger('agent_id')->primary();
            // The settings the row held before this migration wrote it.
            // Null means the row did not exist.
            $table->text('prior_settings')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function toolConfig(): ToolConfigService
    {
        return new ToolConfigService(
            $this->securityManager(),
            new NullLogger(),
            [MediaTool::class],
        );
    }

    /**
     * The install's secret key.
     *
     * The media tool declares no password setting, so the blobs this
     * migration reads are plain JSON and the key is only needed for the
     * legacy whole-blob format an older install may still hold. When no
     * key can be found the manager falls back to a zero key: the blobs
     * that matter decode anyway, and one that does not is caught, which
     * leaves that agent exactly as it is.
     */
    private function securityManager(): SecurityManager
    {
        $envKey = $_ENV['SPORA_SECRET_KEY'] ?? getenv('SPORA_SECRET_KEY');
        if (is_string($envKey) && $envKey !== '') {
            $decoded = base64_decode($envKey, strict: true);
            if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return new SecurityManager($decoded);
            }
        }

        $keyPath = $this->keyPath();
        if ($keyPath !== null) {
            try {
                return SecurityManager::fromKeyFile($keyPath);
            } catch (Throwable) {
                // A missing or malformed key file falls through to the
                // zero-key manager below.
            }
        }

        return new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    private function keyPath(): ?string
    {
        $envPath = $_ENV['SPORA_KEY_PATH'] ?? getenv('SPORA_KEY_PATH');
        if (is_string($envPath) && $envPath !== '') {
            return $envPath;
        }

        $paths = new Paths($this->basePath());
        $config = $this->loadConfigFile($paths->config());

        $configured = $config['SPORA_KEY_PATH'] ?? $config['key_path'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $conventional = $paths->storage('secret.key');

        return is_file($conventional) ? $conventional : null;
    }

    /**
     * The project's `config.php`, read the way the app reads it.
     *
     * @return array<mixed, mixed>
     */
    private function loadConfigFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $loaded = require $path;

        return is_array($loaded) ? $loaded : [];
    }

    private function basePath(): string
    {
        return defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
    }
};
