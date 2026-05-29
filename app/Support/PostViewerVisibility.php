<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Welke gebruikers zijn voor deze viewer "zichtbaar" rondom een specifieke
 * post? Een gebruiker is zichtbaar als:
 *
 *   - het de viewer zelf is, OF
 *   - de gebruiker en de viewer minstens één van de post's circles delen
 *     (als owner of als member).
 *
 * Gebruikt door comment- en like-listings om hidden authors/likers te
 * filteren of als placeholder te markeren.
 */
final class PostViewerVisibility
{
    /**
     * @param  Collection<int, string>  $sharedCircleIds  intersect van post-circles en viewer-circles
     */
    private function __construct(
        public readonly string $viewerId,
        public readonly Collection $sharedCircleIds,
    ) {}

    public static function for(User $viewer, Post $post): self
    {
        $shared = $post->circles()
            ->where(function ($q) use ($viewer) {
                $q->where('circles.user_id', $viewer->id)
                    ->orWhereHas('members', fn ($m) => $m->where('users.id', $viewer->id));
            })
            ->pluck('circles.id');

        return new self($viewer->id, $shared);
    }

    /**
     * Bulk-bepaal de subset van $candidateUserIds die zichtbaar is.
     *
     * @param  Collection<int, string>  $candidateUserIds
     * @return Collection<string, string> flipped collection: user_id => user_id (snel `has()` lookup)
     */
    public function visibleSubset(Collection $candidateUserIds): Collection
    {
        if ($candidateUserIds->isEmpty()) {
            return collect();
        }

        if ($this->sharedCircleIds->isEmpty()) {
            return collect([$this->viewerId => $this->viewerId]);
        }

        $memberIds = DB::table('circle_user')
            ->whereIn('user_id', $candidateUserIds)
            ->whereIn('circle_id', $this->sharedCircleIds)
            ->distinct()
            ->pluck('user_id');

        $ownerIds = DB::table('circles')
            ->whereIn('user_id', $candidateUserIds)
            ->whereIn('id', $this->sharedCircleIds)
            ->pluck('user_id');

        return $memberIds->concat($ownerIds)->push($this->viewerId)->unique()->flip();
    }

    /**
     * Beperk een Likes/Comments query op `user_id` tot zichtbare auteurs.
     * Voor oude clients die geen placeholders ondersteunen — privacy
     * gewaarborgd via subquery filter, geen leaks in pagination.
     *
     * Untyped parameter omdat de caller een Relation óf een Builder passt,
     * en beide hebben dezelfde `where`/`orWhereIn` API.
     */
    public function scopeLikesQuery(mixed $query): void
    {
        $this->scopeVisibleUsers($query, 'user_id');
    }

    /**
     * Beperk een query tot zichtbare gebruikers op de gegeven kolom: de viewer
     * zelf of iedereen die een gedeelde circle deelt (als member of als owner).
     * Wordt gebruikt voor like/comment-listings én notificatie-ontvangers.
     *
     * Untyped query-parameter omdat de caller een Relation, een Eloquent- óf
     * een Query-Builder passt; allemaal delen ze de `where`/`orWhereIn` API.
     */
    public function scopeVisibleUsers(mixed $query, string $column = 'user_id'): void
    {
        $query->where(function ($q) use ($column) {
            $q->where($column, $this->viewerId);

            if ($this->sharedCircleIds->isNotEmpty()) {
                $q->orWhereIn($column, function ($sub) {
                    $sub->select('user_id')
                        ->from('circle_user')
                        ->whereIn('circle_id', $this->sharedCircleIds);
                })->orWhereIn($column, function ($sub) {
                    $sub->select('user_id')
                        ->from('circles')
                        ->whereIn('id', $this->sharedCircleIds);
                });
            }
        });
    }
}
