<?php

use App\Mail\EmailTemplates\EmailTemplateRegistry;
use App\Mail\EmailTemplates\EmailTemplateRenderer;
use App\Models\EmailTemplate;

it('renders the requested locale and replaces placeholders', function () {
    EmailTemplate::query()->where('key', EmailTemplateRegistry::CIRCLE_INVITATION)->update([
        'subject_nl' => '{inviter_name} heeft je uitgenodigd',
        'body_nl' => 'Hallo, {inviter_name} heeft je uitgenodigd.',
        'subject_en' => '{inviter_name} has invited you',
        'body_en' => 'Hello, {inviter_name} has invited you.',
    ]);

    $rendered = app(EmailTemplateRenderer::class)->render(
        EmailTemplateRegistry::CIRCLE_INVITATION,
        ['inviter_name' => 'Sophie'],
        'nl',
    );

    expect($rendered['subject'])->toBe('Sophie heeft je uitgenodigd')
        ->and($rendered['body'])->toStartWith('Hallo, Sophie heeft je uitgenodigd.')
        ->and($rendered['body'])->toContain('Groetjes,')
        ->and($rendered['body'])->toContain('Nicky van Innerr')
        ->and($rendered['body'])->toContain('https://mailing.innerr.app/images/nicky.jpg')
        ->and($rendered['body'])->toContain('border-radius: 9999px');
});

it('renders the launch announcement with its Dutch content and signature', function () {
    $rendered = app(EmailTemplateRenderer::class)->render(
        EmailTemplateRegistry::LAUNCH_ANNOUNCEMENT,
        [],
        'nl',
    );

    expect($rendered['subject'])->toBe('Het is zover: Innerr is live! 🎉')
        ->and($rendered['body'])->toContain('Innerr is vanaf vandaag officieel live')
        ->and($rendered['body'])->toContain('betaal jij nooit abonnementskosten')
        ->and($rendered['body'])->toContain('Groetjes,')
        ->and($rendered['body'])->toContain('Nicky van Innerr');
});

it('falls back to english when the requested locale is empty', function () {
    EmailTemplate::query()->where('key', EmailTemplateRegistry::CIRCLE_INVITATION)->update([
        'subject_fr' => null,
        'body_fr' => '',
        'subject_en' => '{inviter_name} has invited you',
        'body_en' => 'Hello, {inviter_name} has invited you.',
    ]);

    $rendered = app(EmailTemplateRenderer::class)->render(
        EmailTemplateRegistry::CIRCLE_INVITATION,
        ['inviter_name' => 'Sophie'],
        'fr',
    );

    expect($rendered['subject'])->toBe('Sophie has invited you')
        ->and($rendered['body'])->toStartWith('Hello, Sophie has invited you.')
        ->and($rendered['body'])->toContain('À bientôt,')
        ->and($rendered['body'])->toContain('Nicky de Innerr');
});

it('falls back to registry defaults when no database row exists', function () {
    EmailTemplate::query()->where('key', EmailTemplateRegistry::CIRCLE_INVITATION)->delete();

    $rendered = app(EmailTemplateRenderer::class)->render(
        EmailTemplateRegistry::CIRCLE_INVITATION,
        ['inviter_name' => 'Sophie', 'circle_name' => 'Family'],
        'en',
    );

    expect($rendered['subject'])->toBe('Sophie has invited you to Family')
        ->and($rendered['body'])->toContain('Sophie has invited you to join the circle "Family"')
        ->and($rendered['body'])->toContain('Cheers,')
        ->and($rendered['body'])->toContain('Nicky from Innerr');
});

it('skips the signature for raw_html templates', function () {
    EmailTemplate::query()->where('key', EmailTemplateRegistry::EARLY_ADOPTERS)->update([
        'body_format' => EmailTemplate::FORMAT_RAW_HTML,
        'body_nl' => '<html><body>Hi</body></html>',
    ]);

    $rendered = app(EmailTemplateRenderer::class)->render(
        EmailTemplateRegistry::EARLY_ADOPTERS,
        [],
        'nl',
    );

    expect($rendered['body'])->toBe('<html><body>Hi</body></html>')
        ->and($rendered['body'])->not->toContain('Groetjes,')
        ->and($rendered['body'])->not->toContain('mailing.innerr.app/images/nicky.jpg');
});

it('leaves unknown placeholders untouched and appends the Nicky signature', function () {
    EmailTemplate::query()->where('key', EmailTemplateRegistry::CIRCLE_INVITATION)->update([
        'subject_en' => '{inviter_name} invited {nonexistent}',
        'body_en' => 'Body {missing}.',
    ]);

    $rendered = app(EmailTemplateRenderer::class)->render(
        EmailTemplateRegistry::CIRCLE_INVITATION,
        ['inviter_name' => 'Sophie'],
        'en',
    );

    expect($rendered['subject'])->toBe('Sophie invited {nonexistent}')
        ->and($rendered['body'])->toStartWith('Body {missing}.')
        ->and($rendered['body'])->toContain('Nicky from Innerr');
});
