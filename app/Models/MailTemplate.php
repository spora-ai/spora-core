<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Spora\Services\Mail\MailTemplateRenderer;
use Spora\Services\Mail\RenderedTemplate;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $subject
 * @property string|null $body          Markdown source for the email body
 * @property string|null $body_html     Optional raw HTML shell; may contain `{markdown_html}` token
 */
final class MailTemplate extends Model
{
    protected $table = 'mail_templates';

    protected $fillable = [
        'name',
        'subject',
        'body',
        'body_html',
    ];

    /**
     * Render the template with the given variables.
     *
     * The Markdown source in {@code body} is converted to both HTML and
     * plain-text by the bound {@see MailTemplateRenderer}; the optional
     * `body_html` shell wraps the rendered Markdown when it contains a
     * `{markdown_html}` token.
     */
    public function render(array $variables): RenderedTemplate
    {
        return MailTemplateRenderer::createDefault()
            ->render($variables, $this->subject ?? '', $this->body, $this->body_html);
    }
}
