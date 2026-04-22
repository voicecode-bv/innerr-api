<?php

use App\Models\Circle;
use App\Models\CircleInvitation;
use App\Models\User;
use App\Notifications\CircleInvitationAcceptedNotification;
use App\Notifications\CircleInvitationNotification;
use App\Notifications\GdprExportReady;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('mail.default', 'array');
    App::setLocale('en');
});

it('renders mail notifications in the recipient preferred locale', function () {
    $user = User::factory()->create(['locale' => 'nl']);

    config()->set('gdpr.export.disk', 'exports');
    config()->set('gdpr.export.directory', 'gdpr-exports');
    Storage::fake('exports');
    Storage::disk('exports')->put('gdpr-exports/1/fake.zip', 'x');

    $user->notify(new GdprExportReady('gdpr-exports/1/fake.zip'));

    $messages = app('mailer')->getSymfonyTransport()->messages();

    expect($messages)->toHaveCount(1);

    $email = $messages[0]->getOriginalMessage();
    $body = $email->getHtmlBody();

    expect($email->getSubject())->toBe('Je data-export staat klaar')
        ->and($body)->toContain('Hallo '.$user->name)
        ->and($body)->toContain('Je persoonlijke data-export staat klaar om te downloaden.')
        ->and($body)->toContain('Download je gegevens')
        ->and($body)->toContain('Groet,')
        ->and($body)->toContain('Werkt de');
});

it('renders the mail in English for recipients whose locale is en', function () {
    App::setLocale('nl');
    $user = User::factory()->create(['locale' => 'en']);

    $inviter = User::factory()->create();
    $invitation = CircleInvitation::factory()->create([
        'user_id' => $user->id,
        'inviter_id' => $inviter->id,
    ]);

    $user->notify(new CircleInvitationNotification($invitation));

    $email = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();

    expect($email->getSubject())->toContain('has invited you')
        ->and($email->getHtmlBody())->toContain('Hello!');
});

it('localizes the circle invitation accepted mail to the recipient locale', function () {
    $inviter = User::factory()->create(['locale' => 'nl']);
    $invitee = User::factory()->create(['name' => 'Alice']);
    $circle = Circle::factory()->for($inviter)->create(['name' => 'Family']);
    $invitation = CircleInvitation::factory()->create([
        'user_id' => $invitee->id,
        'inviter_id' => $inviter->id,
        'circle_id' => $circle->id,
    ]);

    $inviter->notify(new CircleInvitationAcceptedNotification($invitation, 'Alice'));

    $email = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();

    expect($email->getSubject())->toBe('Alice is lid geworden van Family')
        ->and($email->getHtmlBody())->toContain('Goed nieuws!')
        ->and($email->getHtmlBody())->toContain('is lid geworden van de kring "Family"');
});
