<?php

namespace App\Services;

use App\Enums\ConsentAction;
use App\Enums\ConsentDocument;
use App\Models\ConsentEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConsentService
{
    /** @param array<ConsentDocument> $documents */
    public function acceptCurrent(User $user, array $documents, ?string $ipAddress): void
    {
        DB::transaction(function () use ($user, $documents, $ipAddress): void {
            foreach ($documents as $document) {
                $version = $this->currentVersion($document);
                $latest = ConsentEvent::query()
                    ->where('user_id', $user->id)
                    ->latestForDocument($document)
                    ->first();

                if ($latest?->action === ConsentAction::Accepted && $latest->document_version === $version) {
                    continue;
                }

                ConsentEvent::query()->create([
                    'user_id' => $user->id,
                    'document' => $document,
                    'document_version' => $version,
                    'action' => ConsentAction::Accepted,
                    'ip_hash' => $this->ipHash($ipAddress),
                    'occurred_at' => now(),
                ]);
            }
        });
    }

    public function revoke(User $user, ConsentDocument $document, ?string $ipAddress): void
    {
        DB::transaction(function () use ($user, $document, $ipAddress): void {
            $latest = ConsentEvent::query()
                ->where('user_id', $user->id)
                ->latestForDocument($document)
                ->first();

            if ($latest?->action === ConsentAction::Revoked) {
                return;
            }

            ConsentEvent::query()->create([
                'user_id' => $user->id,
                'document' => $document,
                'document_version' => $this->currentVersion($document),
                'action' => ConsentAction::Revoked,
                'ip_hash' => $this->ipHash($ipAddress),
                'occurred_at' => now(),
            ]);
        });
    }

    public function hasCurrentRequiredConsents(User $user): bool
    {
        foreach ([ConsentDocument::Offer, ConsentDocument::Privacy] as $document) {
            $latest = ConsentEvent::query()
                ->where('user_id', $user->id)
                ->latestForDocument($document)
                ->first();

            if ($latest?->action !== ConsentAction::Accepted || $latest->document_version !== $this->currentVersion($document)) {
                return false;
            }
        }

        return true;
    }

    public function currentVersion(ConsentDocument $document): string
    {
        $key = $document === ConsentDocument::Offer ? 'offer_version' : 'privacy_version';
        $urlKey = $document === ConsentDocument::Offer ? 'offer_url' : 'privacy_url';
        $version = config("tender.legal.{$key}");
        $url = config("tender.legal.{$urlKey}");

        if (! is_string($version) || $version === '' || ! is_string($url) || $url === '') {
            throw new LegalDocumentsUnavailableException;
        }

        return $version;
    }

    private function ipHash(?string $ipAddress): ?string
    {
        if ($ipAddress === null || $ipAddress === '') {
            return null;
        }

        return hash_hmac('sha256', $ipAddress, (string) config('app.key'));
    }
}
