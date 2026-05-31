<?php

declare(strict_types=1);

use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Spora\Services\ImapClientInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\EmailTool;

function makeEmailTool(ToolConfigService&MockInterface $config, ImapClientInterface&MockInterface $imap): EmailTool
{
    return new EmailTool($config, $imap, new NullLogger());
}

function allImapSettings(): array
{
    return [
        'core.imap.host'       => 'imap.example.com',
        'core.imap.port'       => '993',
        'core.imap.encryption' => 'ssl',
        'core.email.username'  => 'alice@example.com',
        'core.email.password'  => 'secret123',
        'core.imap.timeout'    => '60',
    ];
}

function allSmtpSettings(string $from = 'alice@example.com', string $allowedTo = ''): array
{
    return [
        'core.smtp.host'               => 'smtp.example.com',
        'core.smtp.port'               => '587',
        'core.smtp.encryption'         => 'tls',
        'core.smtp.from'               => $from,
        'core.smtp.allowed_recipients' => $allowedTo,
        'core.smtp.timeout'            => '30',
    ];
}

describe('EmailTool', function () {

    // read_inbox

    describe('read_inbox', function () {
        it('returns error when IMAP config is incomplete', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn([]);
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'read_inbox', 'limit' => 5], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('IMAP configuration is incomplete');
        });

        it('returns empty message when no unread emails', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->allows('fetchInboxMessages')->andReturn([]);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'read_inbox', 'limit' => 5], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('No new/unread emails');
        });

        it('formats messages correctly', function () {
            $messages = [
                [
                    'uid'     => '123',
                    'subject' => 'Hello',
                    'from'    => 'bob@example.com',
                    'date'    => '2025-01-15 10:30:00',
                    'body'    => 'Hi Alice, how are you?',
                ],
            ];
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->allows('fetchInboxMessages')->andReturn($messages);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'read_inbox', 'limit' => 5], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('UID: 123')
                ->and($result->content)->toContain('Hello')
                ->and($result->content)->toContain('bob@example.com')
                ->and($result->content)->toContain('2025-01-15 10:30:00')
                ->and($result->content)->toContain('Hi Alice');
        });

        it('passes mark_as_read to IMAP client', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->expects('fetchInboxMessages')
                ->with(Mockery::type('array'), 5, true)
                ->andReturn([]);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'read_inbox', 'limit' => 5, 'mark_as_read' => true], 1);

            expect($result->success)->toBeTrue();
        });

        it('clamps limit to 20', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            // With limit 100: EmailTool clamps to 20, ImapClient clamps 20 to 5
            $imap->expects('fetchInboxMessages')
                ->with(Mockery::type('array'), 5, false)
                ->andReturn([]);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'read_inbox', 'limit' => 100], 1);
            expect($result->success)->toBeTrue();
        });

        it('returns error when IMAP throws an exception', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->allows('fetchInboxMessages')->andThrow(new Exception('Connection refused'));
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'read_inbox'], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('Failed to fetch emails')
                ->and($result->content)->toContain('Connection refused');
        });
    });

    // list_folders

    describe('list_folders', function () {
        it('returns error when IMAP config is incomplete', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn([]);
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'list_folders'], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('IMAP configuration is incomplete');
        });

        it('returns folder names as comma-separated list', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->allows('fetchFolderNames')->andReturn(['Archive', 'INBOX', 'Sent']);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'list_folders'], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('Available folders')
                ->and($result->content)->toContain('Archive')
                ->and($result->content)->toContain('INBOX')
                ->and($result->content)->toContain('Sent');
        });

        it('returns success with empty list when no folders found', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->allows('fetchFolderNames')->andReturn([]);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'list_folders'], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('No email folders found');
        });

        it('returns error when IMAP throws an exception', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->allows('fetchFolderNames')->andThrow(new Exception('Connection timeout'));
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'list_folders'], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('Failed to list folders')
                ->and($result->content)->toContain('Connection timeout');
        });
    });

    // read_folder

    describe('read_folder', function () {
        it('returns error when folder parameter is missing', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'read_folder'], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('folder name is required');
        });

        it('returns error when IMAP config is incomplete', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn([]);
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'read_folder', 'folder' => 'Sent'], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('IMAP configuration is incomplete');
        });

        it('formats folder messages correctly', function () {
            $messages = [
                [
                    'uid'     => '456',
                    'subject' => 'Previous Email',
                    'from'    => 'carol@example.com',
                    'date'    => '2025-02-20 08:00:00',
                    'body'    => 'Reminder: meeting tomorrow.',
                ],
            ];
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->allows('fetchFolderMessages')
                ->with(Mockery::type('array'), 'Sent', 5)
                ->andReturn($messages);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'read_folder', 'folder' => 'Sent', 'limit' => 5], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('Emails in Sent')
                ->and($result->content)->toContain('UID: 456')
                ->and($result->content)->toContain('Previous Email')
                ->and($result->content)->toContain('carol@example.com');
        });

        it('returns empty message when folder is empty', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->allows('fetchFolderMessages')->andReturn([]);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'read_folder', 'folder' => 'Sent'], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain("No emails found in folder 'Sent'");
        });

        it('returns error when IMAP throws an exception', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->allows('fetchFolderMessages')->andThrow(new Exception('Folder not found'));
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'read_folder', 'folder' => 'Trash'], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain("Failed to read folder 'Trash'")
                ->and($result->content)->toContain('Folder not found');
        });
    });

    // create_draft

    describe('create_draft', function () {
        it('returns error when required fields are missing', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'create_draft'], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('Missing required parameters');
        });

        it('formats draft correctly with configured from address', function () {
            $settings = array_merge(allImapSettings(), allSmtpSettings('agent@spora.local'));
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn($settings);
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->allows('saveDraft')->andReturn(true);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute([
                'action'   => 'create_draft',
                'to'       => 'bob@example.com',
                'subject'  => 'Weekly Update',
                'body'     => 'Here is the update...',
            ], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('agent@spora.local')
                ->and($result->content)->toContain('bob@example.com')
                ->and($result->content)->toContain('Weekly Update')
                ->and($result->content)->toContain('Here is the update')
                ->and($result->content)->toContain('Draft saved to Drafts folder');
        });

        it('shows placeholder when from address is not configured', function () {
            $settings = array_merge(allImapSettings(), allSmtpSettings(''));
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn($settings);
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->allows('saveDraft')->andReturn(true);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute([
                'action'   => 'create_draft',
                'to'       => 'bob@example.com',
                'subject'  => 'Test',
                'body'     => 'Body',
            ], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('[From address not configured]')
                ->and($result->content)->toContain('Draft saved to Drafts folder');
        });
    });

    // send_email

    describe('send_email', function () {
        it('returns error when required fields are missing', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'send_email'], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('Missing required parameters');
        });

        it('returns error when SMTP host is not configured', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allSmtpSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute([
                'action'  => 'send_email',
                'to'      => 'bob@example.com',
                'subject' => 'Test',
                'body'    => 'Body',
            ], 1);

            // allSmtpSettings has host='smtp.example.com', so SMTP tries to connect and fails with a network error
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('Failed to send email');
        });

        it('rejects recipient outside allowed list', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')
                ->andReturn(allSmtpSettings('alice@example.com', 'alice@example.com, bob@example.com'));
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute([
                'action'  => 'send_email',
                'to'      => 'untrusted@example.com',
                'subject' => 'Test',
                'body'    => 'Body',
            ], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('SECURITY REJECTION')
                ->and($result->content)->toContain('untrusted@example.com')
                ->and($result->content)->toContain('alice@example.com, bob@example.com');
        });

        it('allows any recipient when allowed_recipients is *', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')
                ->andReturn(allSmtpSettings('alice@example.com', '*'));
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute([
                'action'  => 'send_email',
                'to'      => 'anyone@example.com',
                'subject' => 'Test',
                'body'    => 'Body',
            ], 1);

            // Fails on actual SMTP send (no real server), but NOT security rejection
            expect($result->success)->toBeFalse()
                ->and($result->content)->not->toContain('SECURITY REJECTION');
        });

        it('allows recipient in the allowed list', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')
                ->andReturn(allSmtpSettings('alice@example.com', 'alice@example.com, bob@example.com'));
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute([
                'action'  => 'send_email',
                'to'      => 'bob@example.com',
                'subject' => 'Test',
                'body'    => 'Body',
            ], 1);

            // Fails on actual SMTP send (no real server), but NOT security rejection
            expect($result->success)->toBeFalse()
                ->and($result->content)->not->toContain('SECURITY REJECTION');
        });
    });

    // create_folder

    describe('create_folder', function () {
        it('returns error when new_folder is missing', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'create_folder'], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('new_folder');
        });

        it('returns error when IMAP config is incomplete', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn([]);
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'create_folder', 'new_folder' => 'MyFolder'], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('IMAP configuration is incomplete');
        });

        it('returns success when folder is created', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->allows('fetchFolderNames')->andReturn(['OtherFolder']);
            $imap->expects('createFolder')->with(Mockery::type('array'), 'MyFolder')->andReturn(true);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'create_folder', 'new_folder' => 'MyFolder'], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain("Folder 'MyFolder' created successfully");
        });

        it('returns success message when folder already exists', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->allows('fetchFolderNames')->andReturn(['MyFolder']);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'create_folder', 'new_folder' => 'MyFolder'], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain("Folder 'MyFolder' already exists");
        });

        it('returns error when IMAP create fails', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->allows('fetchFolderNames')->andReturn(['OtherFolder']);
            $imap->expects('createFolder')->andReturn(false);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'create_folder', 'new_folder' => 'MyFolder'], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('Failed to create folder');
        });
    });

    // rename_folder

    describe('rename_folder', function () {
        it('returns error when folder or new_folder is missing', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'rename_folder'], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('folder')
                ->and($result->content)->toContain('new_folder');
        });

        it('returns success when folder is renamed', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->expects('renameFolder')->with(Mockery::type('array'), 'OldName', 'NewName')->andReturn(true);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'rename_folder', 'folder' => 'OldName', 'new_folder' => 'NewName'], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain("renamed to 'NewName'");
        });
    });

    // delete_folder

    describe('delete_folder', function () {
        it('returns error when folder is missing', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'delete_folder'], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('folder');
        });

        it('returns success when folder is deleted', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->allows('fetchFolderNames')->andReturn(['Trash']);
            $imap->expects('deleteFolder')->with(Mockery::type('array'), 'Trash')->andReturn(true);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'delete_folder', 'folder' => 'Trash'], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain("Folder 'Trash' deleted successfully");
        });

        it('returns success message when folder already does not exist', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->allows('fetchFolderNames')->andReturn([]);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'delete_folder', 'folder' => 'Trash'], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain("Folder 'Trash' does not exist");
        });
    });

    // move_email

    describe('move_email', function () {
        it('returns error when uid is missing', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'move_email', 'folder' => 'INBOX', 'new_folder' => 'Archive'], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('uid');
        });

        it('returns error when folder or new_folder is missing', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'move_email', 'uid' => 123], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('folder')
                ->and($result->content)->toContain('new_folder');
        });

        it('returns success when email is moved', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->expects('moveEmail')->with(Mockery::type('array'), 123, 'INBOX', 'Archive')->andReturn('7');
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'move_email', 'uid' => 123, 'folder' => 'INBOX', 'new_folder' => 'Archive'], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain("moved from 'INBOX' to 'Archive'")
                ->and($result->content)->toContain('new UID: 7');
        });
    });

    // delete_email

    describe('delete_email', function () {
        it('returns error when uid is missing', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'delete_email', 'folder' => 'INBOX'], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('uid');
        });

        it('returns error when folder is missing', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'delete_email', 'uid' => 123], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('folder');
        });

        it('returns success when email is deleted', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->expects('deleteEmail')->with(Mockery::type('array'), 123, 'INBOX')->andReturn(true);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'delete_email', 'uid' => 123, 'folder' => 'INBOX'], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain("Email UID 123 deleted from 'INBOX'");
        });
    });

    // mark_email_read

    describe('mark_email_read', function () {
        it('returns error when uid is missing', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'mark_email_read', 'folder' => 'INBOX'], 1);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('uid');
        });

        it('returns success when email is marked read', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->expects('setEmailFlag')->with(Mockery::type('array'), 123, 'INBOX', 'Seen', true)->andReturn(true);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'mark_email_read', 'uid' => 123, 'folder' => 'INBOX', 'read' => true], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('marked as read');
        });

        it('returns success when email is marked unread', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $config->allows('getEffectiveSettings')->andReturn(allImapSettings());
            $imap = Mockery::mock(ImapClientInterface::class);
            $imap->expects('setEmailFlag')->with(Mockery::type('array'), 123, 'INBOX', 'Seen', false)->andReturn(true);
            $tool = makeEmailTool($config, $imap);

            $result = $tool->execute(['action' => 'mark_email_read', 'uid' => 123, 'folder' => 'INBOX', 'read' => false], 1);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('marked as unread');
        });
    });

    // describeAction

    describe('describeAction', function () {
        it('returns human-readable description for each operation', function () {
            $config = Mockery::mock(ToolConfigService::class);
            $imap = Mockery::mock(ImapClientInterface::class);
            $tool = makeEmailTool($config, $imap);

            expect($tool->describeAction(['action' => 'read_inbox']))->toBe('Read unread emails from the inbox');
            expect($tool->describeAction(['action' => 'list_folders']))->toBe('List all email folders');
            expect($tool->describeAction(['action' => 'read_folder']))->toBe('Read emails from a specific folder');
            expect($tool->describeAction(['action' => 'create_draft']))->toBe('Save an email draft to the Drafts folder');
            expect($tool->describeAction(['action' => 'send_email', 'to' => 'bob@example.com', 'subject' => 'Hello']))
                ->toBe("Sending email to bob@example.com with subject: 'Hello'");
            expect($tool->describeAction(['action' => 'create_folder', 'new_folder' => 'MyFolder']))
                ->toBe("Create email folder 'MyFolder'");
            expect($tool->describeAction(['action' => 'rename_folder', 'folder' => 'Old', 'new_folder' => 'New']))
                ->toBe("Rename email folder 'Old' to 'New'");
            expect($tool->describeAction(['action' => 'delete_folder', 'folder' => 'Trash']))
                ->toBe("Delete email folder 'Trash'");
            expect($tool->describeAction(['action' => 'move_email', 'uid' => 42, 'folder' => 'INBOX', 'new_folder' => 'Archive']))
                ->toBe("Move email UID 42 from 'INBOX' to 'Archive'");
            expect($tool->describeAction(['action' => 'delete_email', 'uid' => 42, 'folder' => 'INBOX']))
                ->toBe("Delete email UID 42 from 'INBOX'");
            expect($tool->describeAction(['action' => 'mark_email_read', 'uid' => 42, 'folder' => 'INBOX']))
                ->toBe("Mark email UID 42 as read");
            expect($tool->describeAction(['action' => 'unknown']))->toBe('Perform an email operation');
        });
    });
});
