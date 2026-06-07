<?php

use App\Mail\EmailTemplates\EmailTemplateRegistry;
use App\Models\EmailTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $definition = EmailTemplateRegistry::get(EmailTemplateRegistry::SUPPORT_REQUEST);

        if ($definition === null) {
            return;
        }

        $row = [
            'id' => (string) Str::uuid(),
            'key' => EmailTemplateRegistry::SUPPORT_REQUEST,
            'body_format' => $definition['format'] ?? EmailTemplate::FORMAT_MARKDOWN_MESSAGE,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach (['nl', 'en', 'fr'] as $locale) {
            $defaults = $definition['defaults'][$locale] ?? null;
            $row["subject_{$locale}"] = $defaults['subject'] ?? null;
            $row["body_{$locale}"] = $defaults['body'] ?? null;
        }

        DB::table('email_templates')->updateOrInsert(
            ['key' => EmailTemplateRegistry::SUPPORT_REQUEST],
            $row,
        );
    }

    public function down(): void
    {
        DB::table('email_templates')
            ->where('key', EmailTemplateRegistry::SUPPORT_REQUEST)
            ->delete();
    }
};
