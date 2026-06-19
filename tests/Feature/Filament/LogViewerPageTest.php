<?php

use App\Filament\Pages\LogViewer;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->logName = 'pest-logviewer-'.uniqid().'.log';
    $this->logPath = storage_path('logs').'/'.$this->logName;

    file_put_contents($this->logPath, implode("\n", [
        '[2026-06-19 10:00:00] testing.INFO: User logged in',
        '[2026-06-19 10:01:00] testing.ERROR: Something exploded',
        'Stack trace:',
        '#0 /app/Foo.php(12): boom()',
        '[2026-06-19 10:02:00] testing.WARNING: Disk almost full',
        '',
    ]));
});

afterEach(function () {
    @unlink($this->logPath);
});

it('lists log files and shows entries newest first', function () {
    Livewire::test(LogViewer::class, ['file' => $this->logName])
        ->assertSuccessful()
        ->assertSee('Disk almost full')
        ->assertSee('Something exploded')
        ->assertSee('User logged in')
        ->assertSeeInOrder(['Disk almost full', 'Something exploded', 'User logged in']);
});

it('filters entries by level', function () {
    Livewire::test(LogViewer::class, ['file' => $this->logName])
        ->set('level', 'ERROR')
        ->assertSee('Something exploded')
        ->assertDontSee('User logged in')
        ->assertDontSee('Disk almost full');
});

it('filters entries by search term', function () {
    Livewire::test(LogViewer::class, ['file' => $this->logName])
        ->set('search', 'exploded')
        ->assertSee('Something exploded')
        ->assertDontSee('User logged in');
});

it('rejects filenames outside the logs directory', function () {
    Livewire::test(LogViewer::class)
        ->set('file', '../../.env')
        ->assertSet('file', '../../.env')
        ->call('entries')
        ->assertReturned([]);
});

it('is only accessible to admins', function () {
    expect(LogViewer::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->create(['admin' => false]));

    expect(LogViewer::canAccess())->toBeFalse();
});
