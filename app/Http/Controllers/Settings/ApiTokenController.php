<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ApiTokenRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/Tokens', [
            'tokens' => $this->tokensFor($this->user()),
            'createdToken' => $this->justCreatedToken(),
        ]);
    }

    public function store(ApiTokenRequest $request): RedirectResponse
    {
        $token = $this->user()->createToken($request->string('name')->toString());

        // The only moment the plaintext exists. It is flashed, never stored: Sanctum
        // persists a hash, so nothing can re-derive or re-display this afterwards.
        return to_route('tokens.index')->with('createdToken', [
            'name' => $token->accessToken->name,
            'plain' => $token->plainTextToken,
        ]);
    }

    public function destroy(PersonalAccessToken $token): RedirectResponse
    {
        // Scoped to the acting user: a token id from someone else's account is a 404,
        // not a successful revoke.
        abort_unless(
            $token->tokenable_id === $this->user()->id && $token->tokenable_type === $this->user()->getMorphClass(),
            404,
        );

        $token->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token revoked.')]);

        return to_route('tokens.index');
    }

    /**
     * Read straight off the session rather than through a shared prop: session flash
     * survives exactly one request, which is precisely the "shown once" guarantee this
     * needs. Nothing re-derives it afterwards, because only a hash is stored.
     *
     * @return array{name: string, plain: string}|null
     */
    private function justCreatedToken(): ?array
    {
        $flashed = session('createdToken');

        if (! is_array($flashed)) {
            return null;
        }

        $name = $flashed['name'] ?? null;
        $plain = $flashed['plain'] ?? null;

        if (! is_string($name) || ! is_string($plain)) {
            return null;
        }

        return ['name' => $name, 'plain' => $plain];
    }

    /** @return list<array{id: int, name: string, last_used_at: string|null, created_at: string|null}> */
    private function tokensFor(User $user): array
    {
        $rows = [];

        foreach ($user->tokens()->latest()->get() as $token) {
            $rows[] = [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
            ];
        }

        return $rows;
    }

    private function user(): User
    {
        $user = request()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
