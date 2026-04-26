# TODO — security & performance audit follow-ups

## Design calls needed

### #5 Register email enumeration
`POST /api/auth/register` returns "the email has already been taken" (422) when an email exists. This leaks account existence.
- Option A: silent-success — always return 201 with a token-shaped response, send "someone tried to register with your email" notification to the existing user.
- Option B: accept the leak (status quo) — register UX is clearer.
- Decision required before any code change.

### #9 Paginate comments inline in `GET /api/posts/{post}`
Currently the entire comment tree (top-level + replies) loads in one response.
- Option A: cap to 20 latest top-level comments inline, no follow-up endpoint (clients lose older).
- Option B: extract to a separate paginated `GET /api/posts/{post}/comments` and stop returning them inline (breaking change for the app).
- Decision required before any code change.

## Operational follow-ups

- Run migration `2026_04_26_080257_add_missing_performance_indexes` on staging then prod. On a populated production DB, build the indexes `CONCURRENTLY` to avoid table locks.
- Confirm Horizon has enough capacity for the post-creation chunked fan-out introduced in #10 (each new post now queues N notifications, one per recipient).
- Mobile app updates required:
  - Handle paginated `GET /api/notifications` response (`data` + `links` + `meta`), use `?page=N` for older notifications.
  - Stop reading `data.likes` from `GET /api/posts/{post}` (removed). Use `likes_count` / `is_liked`, or call `GET /api/posts/{post}/likes` for the full list.
  - Handle 429 on `POST /api/circles/{circle}/members` (now throttled to 20/hour).

## Optional cleanups (not in original audit)

- `Post::booted()` deleting uses `whereRaw("data::jsonb->>'post_id' = ?", ...)` — won't use any index. Consider `CREATE INDEX ... ON notifications ((data->>'post_id'))` if delete-cascade for posts becomes slow at scale.
- `CircleResource` returns `members_count + 1` to include the owner. Only worth touching if off-by-one bugs surface after ownership transfers.
- Normalize `users.email` to lowercase at registration + OAuth, so the `LOWER()` workaround in `AuthController` (already removed) stays unnecessary as data grows.

## Pre-existing test failures (unrelated, present on `main` before this work)

- `Tests\Feature\Notifications\NotificationPreferenceFcmTest` — 2 tests
- `Tests\Feature\Api\NotificationPreferenceControllerTest > it returns default preferences when none are set`
- `Tests\Feature\Api\ServiceKeyControllerTest` — 2 tests
- `Tests\Feature\Api\PostControllerTest > it converts heic uploads to jpeg` — environmental: `heif-convert` binary not installed locally
