<?php

namespace App\Mail\EmailTemplates;

use App\Models\EmailTemplate;
use Illuminate\Support\Facades\App;

class EmailTemplateRenderer
{
    /**
     * @param  array<string, string|int|float|null>  $placeholders
     * @return array{subject: string, body: string}
     */
    public function render(string $key, array $placeholders = [], ?string $locale = null): array
    {
        $locale ??= App::getLocale();
        $template = EmailTemplate::query()->where('key', $key)->first();

        [$subject, $body] = $template instanceof EmailTemplate
            ? [$template->subjectFor($locale), $template->bodyFor($locale)]
            : $this->defaults($key, $locale);

        if (! $this->isRawHtml($template, $key) && $this->signatureEnabled($key)) {
            $body = $this->appendSignature($body, $locale);
        }

        return [
            'subject' => $this->replacePlaceholders($subject, $placeholders),
            'body' => $this->replacePlaceholders($body, $placeholders),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function defaults(string $key, string $locale): array
    {
        $definition = EmailTemplateRegistry::get($key);

        if ($definition === null) {
            return ['', ''];
        }

        $defaults = $definition['defaults'][$locale]
            ?? $definition['defaults'][EmailTemplate::FALLBACK_LOCALE]
            ?? ['subject' => '', 'body' => ''];

        return [(string) $defaults['subject'], (string) $defaults['body']];
    }

    private function isRawHtml(?EmailTemplate $template, string $key): bool
    {
        if ($template instanceof EmailTemplate) {
            return $template->isRawHtml();
        }

        return (EmailTemplateRegistry::get($key)['format'] ?? EmailTemplate::FORMAT_MARKDOWN_MESSAGE)
            === EmailTemplate::FORMAT_RAW_HTML;
    }

    private function signatureEnabled(string $key): bool
    {
        return EmailTemplateRegistry::get($key)['signature'] ?? true;
    }

    private function appendSignature(string $body, string $locale): string
    {
        $signature = EmailSignature::template($locale);

        if ($body === '') {
            return $signature;
        }

        return rtrim($body)."\n\n".$signature;
    }

    /**
     * @param  array<string, string|int|float|null>  $placeholders
     */
    private function replacePlaceholders(string $content, array $placeholders): string
    {
        if ($content === '' || $placeholders === []) {
            return $content;
        }

        $tokens = [];

        foreach ($placeholders as $name => $value) {
            $tokens['{'.$name.'}'] = (string) ($value ?? '');
        }

        return strtr($content, $tokens);
    }
}
