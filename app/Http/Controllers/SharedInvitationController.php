<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Services\GuestInvitationService;
use App\Services\ReservationCardState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class SharedInvitationController extends Controller
{
    public function issue(Reservation $reservation): JsonResponse
    {
        Gate::authorize('view', $reservation);
        $this->ensureOpen($reservation);

        return response()->json(['invitation_url' => URL::temporarySignedRoute('invitations.join', $reservation->reserved_at->toImmutable()->subMinutes(30), ['reservation' => $reservation->id])]);
    }

    public function show(Request $request, Reservation $reservation, GuestInvitationService $invitations): Response|RedirectResponse
    {
        $existing = $request->session()->get('shared_invitations.'.$reservation->id);
        if ($reservation->status !== 'cancelled' && is_string($existing) && $invitations->resolve(basename($existing)) !== null) {
            return redirect()->to($existing);
        }
        $this->ensureOpen($reservation);

        return response()->view('join-invitation', ['reservation' => $reservation]);
    }

    public function join(Request $request, Reservation $reservation, GuestInvitationService $invitations): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', 'not_regex:/[\p{Cc}\p{Cf}]/u']]);
        $key = 'shared_invitations.'.$reservation->id;
        $existing = $request->session()->get($key);
        if (is_string($existing) && $invitations->resolve(basename($existing)) !== null) {
            return redirect()->to($existing);
        }
        $issued = DB::transaction(function () use ($reservation, $data, $invitations) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $this->ensureOpen($locked);
            if ($locked->guests()->active()->count() >= $locked->guest_count) {
                throw ValidationException::withMessages(['name' => 'All guest places have been claimed. Please contact the reservation owner.']);
            }
            $issued = $invitations->create($locked, ['name' => trim($data['name'])]);
            $locked->guests()->whereKey($issued['guest_id'])->update(['name_confirmed_at' => now()]);

            return $issued;
        }, attempts: 3);
        $request->session()->put($key, $issued['invitation_url']);

        return redirect()->to($issued['invitation_url']);
    }

    private function ensureOpen(Reservation $reservation): void
    {
        abort_unless(ReservationCardState::acceptsPreOrder($reservation) && ($reservation->preOrder === null || $reservation->preOrder->status === 'draft'), 410, 'This invitation is no longer accepting guests.');
    }
}
