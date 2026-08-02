<?php

use Illuminate\Support\Facades\Route;

/*
| Project module - Trello-style boards.
| Namespace `project::` maps to Modules/Project/resources/views/components.
*/

Route::middleware('auth')->prefix('projects')->name('projects.')->group(function () {
    Route::livewire('/', 'project::boards')->name('boards');
    Route::livewire('/archive', 'project::archive')->name('archive');
    Route::livewire('/{board}/settings', 'project::board-settings')->name('board-settings');
});
