<?php

declare(strict_types=1);

use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Models\ScheduledRun;
use Spora\Services\Exceptions\AgentNotFoundException;
use Spora\Services\Exceptions\EmailTemplateParseException;
use Spora\Services\Exceptions\MemoryValidationException;
use Spora\Services\Exceptions\PromptTemplateMissingException;
use Spora\Services\Exceptions\ScheduledRunNotFoundException;
use Spora\Services\MemoryService;
use Spora\Services\PromptTemplateService;
use Spora\Services\ScheduledRunService;

/**
 * Shared helpers
 */

function makeMemoryServiceForExceptions(): MemoryService
{
    return new MemoryService();
}

function makePromptTemplateServiceForExceptions(): PromptTemplateService
{
    return new PromptTemplateService();
}

/**
 * @return array{0: int, 1: int} [userId, agentId]
 */
function createServiceExceptionUserAgent(string $email): array
{
    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($auth, "{$seq}{$email}", 'Password1!');

    $agentId = Agent::create([
        'user_id'   => $userId,
        'name'      => 'ExceptionTestAgent',
        'max_steps' => 10,
        'is_active' => true,
    ])->id;

    return [$userId, $agentId];
}

function makeScheduledRunServiceForExceptions(): ScheduledRunService
{
    $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
    $mercure      = Mockery::mock(Spora\Services\MercurePublisherInterface::class);
    $mercure->allows('publish')->andReturn(true);

    return new ScheduledRunService($orchestrator, $mercure);
}

describe('Service exceptions', function (): void {

    describe('AgentNotFoundException', function (): void {
        it('extends RuntimeException', function (): void {
            $e = new AgentNotFoundException('Agent not found');
            expect($e)->toBeInstanceOf(RuntimeException::class);
        });

        it('preserves the message verbatim', function (): void {
            $e = new AgentNotFoundException('Agent not found');
            expect($e->getMessage())->toBe('Agent not found');
        });
    });

    describe('ScheduledRunNotFoundException', function (): void {
        it('extends RuntimeException', function (): void {
            $e = new ScheduledRunNotFoundException('Scheduled run not found');
            expect($e)->toBeInstanceOf(RuntimeException::class);
        });

        it('preserves the message verbatim', function (): void {
            $e = new ScheduledRunNotFoundException('Scheduled run not found');
            expect($e->getMessage())->toBe('Scheduled run not found');
        });
    });

    describe('PromptTemplateMissingException', function (): void {
        it('extends RuntimeException', function (): void {
            $e = new PromptTemplateMissingException('The prompt template assigned to this scheduled run no longer exists.');
            expect($e)->toBeInstanceOf(RuntimeException::class);
        });

        it('preserves the message verbatim', function (): void {
            $e = new PromptTemplateMissingException('The prompt template assigned to this scheduled run no longer exists.');
            expect($e->getMessage())->toBe('The prompt template assigned to this scheduled run no longer exists.');
        });
    });

    describe('EmailTemplateParseException', function (): void {
        it('extends RuntimeException', function (): void {
            $e = new EmailTemplateParseException('Failed to parse email template');
            expect($e)->toBeInstanceOf(RuntimeException::class);
        });

        it('preserves the message verbatim', function (): void {
            $e = new EmailTemplateParseException('Failed to parse email template');
            expect($e->getMessage())->toBe('Failed to parse email template');
        });
    });

    describe('MemoryValidationException', function (): void {
        it('extends RuntimeException', function (): void {
            $e = new MemoryValidationException('name is required');
            expect($e)->toBeInstanceOf(RuntimeException::class);
        });

        it('preserves the message verbatim', function (): void {
            $e = new MemoryValidationException('name is required');
            expect($e->getMessage())->toBe('name is required');
        });
    });
});

describe('MemoryService throws AgentNotFoundException', function (): void {

    it('createAgentMemory throws when the agent does not exist', function (): void {
        [$userId] = createServiceExceptionUserAgent('memory-create@example.com');
        $service = makeMemoryServiceForExceptions();

        expect(fn() => $service->createAgentMemory(9999, $userId, ['name' => 'test']))
            ->toThrow(AgentNotFoundException::class, 'Agent not found');
    });

    it('reorderAgentMemories throws when the agent does not exist', function (): void {
        [$userId] = createServiceExceptionUserAgent('memory-reorder@example.com');
        $service = makeMemoryServiceForExceptions();

        expect(fn() => $service->reorderAgentMemories(9999, $userId, []))
            ->toThrow(AgentNotFoundException::class, 'Agent not found');
    });
});

describe('PromptTemplateService throws AgentNotFoundException', function (): void {

    it('createTemplate throws when the agent does not exist', function (): void {
        [$userId] = createServiceExceptionUserAgent('prompt-create@example.com');
        $service = makePromptTemplateServiceForExceptions();

        expect(fn() => $service->createTemplate(9999, $userId, [
            'name'            => 'tpl',
            'prompt_template' => 'hello',
        ]))->toThrow(AgentNotFoundException::class, 'Agent not found');
    });
});

describe('ScheduledRunService throws typed exceptions', function (): void {

    it('createRun throws AgentNotFoundException when the agent does not exist', function (): void {
        $service = makeScheduledRunServiceForExceptions();

        expect(fn() => $service->createRun(9999, 1, ['raw_prompt' => 'x']))
            ->toThrow(AgentNotFoundException::class, 'Agent not found');
    });

    it('triggerRun throws AgentNotFoundException when the agent does not exist', function (): void {
        $service = makeScheduledRunServiceForExceptions();

        expect(fn() => $service->triggerRun(1, 9999, 1))
            ->toThrow(AgentNotFoundException::class, 'Agent not found');
    });

    it('triggerRun throws ScheduledRunNotFoundException when the run does not exist', function (): void {
        $service = makeScheduledRunServiceForExceptions();
        [$userId, $agentId] = createServiceExceptionUserAgent('run-missing@example.com');

        expect(fn() => $service->triggerRun(9999, $agentId, $userId))
            ->toThrow(ScheduledRunNotFoundException::class, 'Scheduled run not found');
    });

    it('triggerRun throws PromptTemplateMissingException when the assigned template was deleted', function (): void {
        $service = makeScheduledRunServiceForExceptions();
        [$userId, $agentId] = createServiceExceptionUserAgent('run-tpl-missing@example.com');

        // We need a ScheduledRun whose template_id points to a non-existent prompt template.
        // SQLite enforces the FK inline, so we have to roll back the auto-started test
        // transaction, turn foreign_keys off, recreate the agent + run with an orphan
        // template_id, and delete the template. Re-creating the agent outside the
        // original transaction is necessary because the rollback above discards it.
        $capsule = \Illuminate\Database\Capsule\Manager::connection();
        $capsule->rollBack();
        $capsule->statement('PRAGMA foreign_keys = OFF');

        $userId  = (int) \Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
            'email'      => 'orphan@example.com',
            'password'   => 'x',
            'username'   => 'orphan',
            'registered' => time(),
        ]);
        $agentId = (int) \Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'user_id'   => $userId,
            'name'      => 'OrphanAgent',
            'max_steps' => 10,
            'is_active' => 1,
        ]);

        $templateId = (int) AgentPromptTemplate::create([
            'agent_id'        => $agentId,
            'name'            => 'orphan template',
            'prompt_template' => 'do stuff',
            'is_active'       => 1,
        ])->id;

        $run = ScheduledRun::create([
            'agent_id'    => $agentId,
            'user_id'     => $userId,
            'template_id' => $templateId,
            'timezone'    => 'UTC',
            'is_active'   => true,
        ]);

        // Delete the template; FK cascade is disabled so the scheduled run survives.
        \Illuminate\Database\Capsule\Manager::table('agent_prompt_templates')
            ->where('id', $templateId)
            ->delete();

        expect(fn() => $service->triggerRun($run->id, $agentId, $userId))
            ->toThrow(PromptTemplateMissingException::class, 'The prompt template assigned to this scheduled run no longer exists.');
    });
});
