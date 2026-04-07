<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CircleController;
use App\Http\Controllers\Api\CircleInvitationController;
use App\Http\Controllers\Api\CircleMemberController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\CommentLikeController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\LikeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\WaitingListEntryController;
use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::get('/media/{path}', MediaController::class)
    ->where('path', '.*')
    ->middleware('signed')
    ->name('api.media');

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('api.auth.login');
Route::post('/auth/register', [AuthController::class, 'register'])->name('api.auth.register');

Route::post('/waiting-list', [WaitingListEntryController::class, 'store'])->middleware('throttle:5,1')->name('api.waiting-list.store');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

    Route::get('/feed', FeedController::class)->name('api.feed');

    Route::get('/posts/{post}', [PostController::class, 'show'])->name('api.posts.show');
    Route::post('/posts', [PostController::class, 'store'])->name('api.posts.store');
    Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('api.posts.destroy');

    Route::post('/posts/{post}/comments', [CommentController::class, 'store'])->name('api.comments.store');
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->name('api.comments.destroy');

    Route::post('/posts/{post}/like', [LikeController::class, 'store'])->name('api.likes.store');
    Route::delete('/posts/{post}/like', [LikeController::class, 'destroy'])->name('api.likes.destroy');

    Route::post('/comments/{comment}/like', [CommentLikeController::class, 'store'])->name('api.comment-likes.store');
    Route::delete('/comments/{comment}/like', [CommentLikeController::class, 'destroy'])->name('api.comment-likes.destroy');

    Route::apiResource('circles', CircleController::class);
    Route::post('/circles/{circle}/photo', [CircleController::class, 'updatePhoto'])->name('api.circles.photo.update');
    Route::delete('/circles/{circle}/photo', [CircleController::class, 'deletePhoto'])->name('api.circles.photo.delete');
    Route::post('/circles/{circle}/members', [CircleMemberController::class, 'store'])->name('api.circle-members.store');
    Route::delete('/circles/{circle}/members/{user}', [CircleMemberController::class, 'destroy'])->name('api.circle-members.destroy');

    Route::delete('/circles/{circle}/invitations/{circleInvitation}', [CircleInvitationController::class, 'destroy'])->name('api.circle-invitations.destroy');
    Route::get('/circle-invitations', [CircleInvitationController::class, 'index'])->name('api.circle-invitations.index');
    Route::post('/circle-invitations/{circleInvitation}/accept', [CircleInvitationController::class, 'accept'])->name('api.circle-invitations.accept');
    Route::post('/circle-invitations/{circleInvitation}/decline', [CircleInvitationController::class, 'decline'])->name('api.circle-invitations.decline');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('api.notifications.index');
    Route::post('/notifications/read', [NotificationController::class, 'markAsRead'])->name('api.notifications.read');

    Route::post('/device-token', [DeviceTokenController::class, 'store'])->name('api.device-token.store');

    Route::put('/profile', [ProfileController::class, 'update'])->name('api.profile.update');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('api.profile.avatar.update');
    Route::delete('/profile/avatar', [ProfileController::class, 'deleteAvatar'])->name('api.profile.avatar.delete');
    Route::get('/profiles/{user:username}', [ProfileController::class, 'show'])->name('api.profiles.show');
    Route::get('/profiles/{user:username}/posts', [ProfileController::class, 'posts'])->name('api.profiles.posts');
});
