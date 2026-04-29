<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $subject
 * @property string|null $body_text
 * @property string|null $body_html
 */
final class MailTemplate extends Model
{
    protected $table = 'mail_templates';

    protected $fillable = [
        'name',
        'subject',
        'body_text',
        'body_html',
    ];

    public function render(array $variables): array
    {
        $replace = static fn(?string $content): ?string => $content === null
            ? null
            : preg_replace_callback('/\{\{(\w+)\}\}/', static fn(array $matches) => $variables[$matches[1]] ?? $matches[0], $content);

        return [
            'subject' => $replace($this->subject),
            'body_text' => $replace($this->body_text),
            'body_html' => $replace($this->body_html),
        ];
    }
}
