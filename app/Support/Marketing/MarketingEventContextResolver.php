<?php

namespace App\Support\Marketing;

use App\Models\EventInstance;
use Illuminate\Support\Str;

class MarketingEventContextResolver
{
    /**
     * @return array{id:int,slug:string,title:string,date:?string}|null
     */
    public function resolve(string $slug): ?array
    {
        $input = trim($slug);
        $normalizedSlug = Str::slug($input);
        if ($normalizedSlug === '') {
            return null;
        }

        $byCanonicalSlug = EventInstance::query()
            ->where('public_slug', $normalizedSlug)
            ->orWhere('public_slug', $input)
            ->first(['id', 'title', 'public_slug', 'starts_at']);
        if ($byCanonicalSlug) {
            return $this->toContext($byCanonicalSlug);
        }

        if (preg_match('/^(\d+)(?:-|$)/', $normalizedSlug, $matches) === 1) {
            $byId = EventInstance::query()->find((int) $matches[1], ['id', 'title', 'public_slug', 'starts_at']);
            if ($byId) {
                return $this->toContext($byId);
            }
        }

        // Legacy fallback for old links that used slugified titles instead of canonical slugs.
        $instance = EventInstance::query()
            ->select(['id', 'title', 'public_slug', 'starts_at'])
            ->orderByDesc('starts_at')
            ->get()
            ->first(function (EventInstance $row) use ($normalizedSlug): bool {
                $candidate = Str::slug((string) $row->title);

                return $candidate === $normalizedSlug;
            });

        return $instance ? $this->toContext($instance) : null;
    }

    /**
     * @return array{id:int,slug:string,title:string,date:?string}
     */
    protected function toContext(EventInstance $instance): array
    {
        return [
            'id' => (int) $instance->id,
            'slug' => trim((string) $instance->public_slug) !== ''
                ? trim((string) $instance->public_slug)
                : Str::slug((string) $instance->title),
            'title' => (string) $instance->title,
            'date' => optional($instance->starts_at)->toDateString(),
        ];
    }
}
