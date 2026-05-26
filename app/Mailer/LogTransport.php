<?php

declare(strict_types=1);

namespace Spora\Mailer;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;

/**
 * A Symfony Mailer transport that logs email metadata instead of sending.
 * Use SPORA_MAIL_DRIVER=log in development to see email activity in spora.log.
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
        $subject = $originalMessage instanceof \Symfony\Component\Mime\Email
            ? $originalMessage->getSubject()
            : null;

        $textBody = $originalMessage instanceof \Symfony\Component\Mime\Email
            ? $originalMessage->getTextBody()
            : null;

        $htmlBody = $originalMessage instanceof \Symfony\Component\Mime\Email
            ? $originalMessage->getHtmlBody()
            : null;

        $this->getLogger()->info(
            '[Spora] Mail sent via log driver',
            [
                'to'       => $toAddresses,
                'from'     => $envelope->getSender()->getAddress(),
                'subject'  => $subject,
                'text_body' => $textBody,
                'html_body' => $htmlBody,
            ],
        );
    }
}
