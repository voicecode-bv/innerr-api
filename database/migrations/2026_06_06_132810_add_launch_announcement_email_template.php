<?php

use App\Mail\EmailTemplates\EmailTemplateRegistry;
use App\Models\EmailTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $key = EmailTemplateRegistry::LAUNCH_ANNOUNCEMENT;
        $definition = EmailTemplateRegistry::get($key);

        if ($definition === null) {
            return;
        }

        $attributes = ['body_format' => $definition['format']];

        foreach (EmailTemplate::SUPPORTED_LOCALES as $locale) {
            $defaults = $definition['defaults'][$locale] ?? null;
            $attributes["subject_{$locale}"] = $defaults['subject'] ?? null;
            $attributes["body_{$locale}"] = $defaults['body'] ?? null;
        }

        EmailTemplate::query()->updateOrCreate(['key' => $key], $attributes);
    }

    public function down(): void
    {
        EmailTemplate::query()
            ->where('key', EmailTemplateRegistry::LAUNCH_ANNOUNCEMENT)
            ->delete();
    }
};
