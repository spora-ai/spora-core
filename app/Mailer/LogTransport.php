<?php

declare(strict_types=1);

namespace Spora\Mailer;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * A Symfony Mailer transport that logs email metadata instead of sending.
 * Use SPORA_MAIL_DRIVER=log in development to see email activity in spora.log.
 *
 * Logs `to`, `from`, `subject`, plus the rendered body (`text` and/or `html`)
 * so operators can verify the message that *would* have been sent without
 * needing a real SMTP server. The body is included verbatim — if the operator
 * does not want message bodies in the log, switch to a different driver.
 */
final class LogTransport extends AbstractTransport
{
    public function __toString(): string
    {
        return 'log://';
    }

    protected function doSend(SentMessage $message): void
    {
        $envelope = $message->getEnvelope();

        $recipients = $envelope->getRecipients();
        $toAddresses = implode(', ', array_map(
            static fn(Address $address): string => $address->getAddress(),
            $recipients,
        ));

        $originalMessage = $message->getOriginalMessage();
        $subject  = $originalMessage instanceof Email ? $originalMessage->getSubject() : null;
        $textBody = $originalMessage instanceof Email ? $originalMessage->getTextBody() : null;
        $htmlBody = $originalMessage instanceof Email ? $originalMessage->getHtmlBody() : null;

        $context = [
            'to'      => $toAddresses,
            'from'    => $envelope->getSender()->getAddress(),
            'subject' => $subject,
        ];
        if ($textBody !== null && $textBody !== '') {
            $context['text'] = $textBody;
        }
        if ($htmlBody !== null && $htmlBody !== '') {
            $context['html'] = $htmlBody;
        }

        $this->getLogger()->info('[Spora] Mail sent via log driver', $context);
    }
}
