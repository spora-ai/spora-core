<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Spora\Http\UserLocaleController;
use Spora\I18n\CatalogLoader;
use Spora\I18n\LocaleResolver;
use Spora\Models\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * `/api/v1/user-preferences/locale`.
 *
 * User-scoped, not principal-scoped: the language belongs to the person,
 * so there is no `?principal_id=` and a group principal cannot override
 * it. That is the whole reason the column is on `users` and not on
 * `principal_preferences` — see migration 0096.
 */
beforeEach(function (): void {
    $this->resolver = new LocaleResolver(new CatalogLoader());
    $this->controller = new UserLocaleController(bootAuthLayer(), $this->resolver);
    $this->auth = bootAuthLayer();
});

function localeUser(string $email = 'locale@example.com'): User
{
    return tap(new User([
        'email'      => $email,
        'password'   => password_hash('Password1!', PASSWORD_BCRYPT),
        'username'   => $email,
        'status'     => 1,
        'verified'   => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
    ]), function (User $user): void {
        $user->save();
    });
}

function putLocale(UserLocaleController $controller, mixed $locale): \Symfony\Component\HttpFoundation\JsonResponse
{
    return $controller->update(new Request([], [], [], [], [], [], (string) json_encode(['locale' => $locale])));
}

it('refuses an unauthenticated caller on both verbs', function (): void {
    expect($this->controller->show()->getStatusCode())->toBe(401)
        ->and($this->controller->update(new Request())->getStatusCode())->toBe(401);
});

it('reports null and the instance default for a user who never chose', function (): void {
    $user = localeUser('never@example.com');
    $this->auth->login($user->email, 'Password1!');

    $payload = json_decode((string) $this->controller->show()->getContent(), true);

    expect($payload['data']['locale'])->toBeNull()
        ->and($payload['data']['resolved'])->toBe('en')
        ->and($payload['data']['available'])->toContain('de');
});

it('round-trips a chosen locale and resolves to it', function (): void {
    $user = localeUser('pick@example.com');
    $this->auth->login($user->email, 'Password1!');

    $update = json_decode((string) putLocale($this->controller, 'de')->getContent(), true);
    expect($update['data'])->toBe(['locale' => 'de', 'resolved' => 'de']);

    $show = json_decode((string) $this->controller->show()->getContent(), true);
    expect($show['data']['locale'])->toBe('de');
});

it('treats null as auto rather than as a missing field', function (): void {
    $user = localeUser('auto@example.com');
    $this->auth->login($user->email, 'Password1!');

    putLocale($this->controller, 'de');
    $cleared = json_decode((string) putLocale($this->controller, null)->getContent(), true);

    expect($cleared['data'])->toBe(['locale' => null, 'resolved' => 'en'])
        ->and(User::find($user->id)?->locale)->toBeNull();
});

it('rejects a locale nobody has a catalogue for', function (): void {
    $user = localeUser('reject@example.com');
    $this->auth->login($user->email, 'Password1!');

    $response = putLocale($this->controller, 'xx');

    expect($response->getStatusCode())->toBe(422)
        ->and(User::find($user->id)?->locale)->toBeNull();
});

it('rejects a malformed body without writing anything', function (): void {
    $user = localeUser('badjson@example.com');
    $this->auth->login($user->email, 'Password1!');

    $response = $this->controller->update(new Request([], [], [], [], [], [], '{not json'));

    expect($response->getStatusCode())->toBe(400)
        ->and(User::find($user->id)?->locale)->toBeNull();
});
